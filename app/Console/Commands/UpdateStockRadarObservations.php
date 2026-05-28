<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateStockRadarObservations extends Command
{
    protected $signature = 'market:update-stock-radar-observations
        {--card-date= : Card date to register, default latest stock_radar_cards date}
        {--check-date= : Price date to check, default latest stock_prices_1d date}';

    protected $description = 'Register daily stock radar picks and track their later price performance.';

    public function handle(): int
    {
        $cardDate = $this->option('card-date') ?: DB::table('stock_radar_cards')->max('card_date');
        $checkDate = $this->option('check-date') ?: DB::table('stock_prices_1d')->max('trade_date');

        if (! $cardDate) {
            $this->warn('No stock radar cards found.');

            return self::SUCCESS;
        }

        $registered = $this->registerCardPicks((string) $cardDate);
        $checked = $checkDate ? $this->checkActiveObservations((string) $cardDate, (string) $checkDate) : 0;

        $this->info("Stock radar observations updated. card_date={$cardDate}, check_date=".($checkDate ?: 'none'));
        $this->line("Registered picks: {$registered}");
        $this->line("Observation checks: {$checked}");

        return self::SUCCESS;
    }

    private function registerCardPicks(string $cardDate): int
    {
        $cards = DB::table('stock_radar_cards')
            ->where('card_date', $cardDate)
            ->get(['card_date', 'card_type', 'stock_id', 'rank', 'confidence_score', 'reasons', 'metrics_payload']);

        $count = 0;
        $now = now();

        foreach ($cards as $card) {
            DB::table('stock_radar_observations')->updateOrInsert(
                [
                    'selected_date' => $card->card_date,
                    'card_type' => $card->card_type,
                    'stock_id' => $card->stock_id,
                ],
                [
                    'entry_rank' => $card->rank,
                    'entry_confidence' => $card->confidence_score,
                    'entry_reasons' => $card->reasons,
                    'entry_metrics' => $card->metrics_payload,
                    'status' => 'active',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function checkActiveObservations(string $currentCardDate, string $checkDate): int
    {
        $active = DB::table('stock_radar_observations')
            ->where('selected_date', '<=', $currentCardDate)
            ->where('status', 'active')
            ->get();

        $currentCards = DB::table('stock_radar_cards')
            ->where('card_date', $currentCardDate)
            ->get(['card_type', 'stock_id'])
            ->mapWithKeys(fn (object $row) => [$row->card_type.':'.$row->stock_id => true]);

        $count = 0;
        $now = now();

        foreach ($active as $observation) {
            $price = DB::table('stock_prices_1d')
                ->where('stock_id', $observation->stock_id)
                ->where('trade_date', $checkDate)
                ->first(['close', 'change', 'change_pct', 'volume']);

            $conditionStillPresent = $currentCards->has($observation->card_type.':'.$observation->stock_id);
            $changePct = $this->changePct($price);
            $days = CarbonImmutable::parse($observation->selected_date)->diffInDays(CarbonImmutable::parse($checkDate));

            DB::table('stock_radar_observation_checks')->updateOrInsert(
                [
                    'stock_radar_observation_id' => $observation->id,
                    'check_date' => $checkDate,
                ],
                [
                    'stock_id' => $observation->stock_id,
                    'days_since_selected' => max(0, (int) $days),
                    'close' => $price?->close,
                    'change' => $price?->change,
                    'change_pct' => $changePct,
                    'volume' => $price?->volume,
                    'condition_still_present' => $conditionStillPresent,
                    'check_payload' => json_encode([
                        'selected_date' => $observation->selected_date,
                        'current_card_date' => $currentCardDate,
                        'has_price' => $price !== null,
                    ], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            DB::table('stock_radar_observations')
                ->where('id', $observation->id)
                ->update([
                    'last_checked_date' => $checkDate,
                    'status' => $conditionStillPresent ? 'active' : 'closed',
                    'closed_at' => $conditionStillPresent ? null : $now,
                    'close_reason' => $conditionStillPresent ? null : 'condition_disappeared',
                    'performance_payload' => json_encode($this->performancePayload((int) $observation->id), JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ]);

            $count++;
        }

        return $count;
    }

    private function changePct(?object $price): ?float
    {
        if (! $price) {
            return null;
        }

        if ($price->change_pct !== null) {
            return (float) $price->change_pct;
        }

        $close = $price->close === null ? null : (float) $price->close;
        $change = $price->change === null ? null : (float) $price->change;

        if ($close === null || $change === null || ($close - $change) == 0.0) {
            return null;
        }

        return round(($change / ($close - $change)) * 100, 4);
    }

    /**
     * @return array<string,mixed>
     */
    private function performancePayload(int $observationId): array
    {
        $checks = DB::table('stock_radar_observation_checks')
            ->where('stock_radar_observation_id', $observationId)
            ->orderBy('check_date')
            ->get(['check_date', 'change_pct']);

        $valid = $checks->whereNotNull('change_pct');

        return [
            'checks' => $checks->count(),
            'valid_checks' => $valid->count(),
            'avg_change_pct' => $valid->count() ? round((float) $valid->avg('change_pct'), 4) : null,
            'max_change_pct' => $valid->count() ? round((float) $valid->max('change_pct'), 4) : null,
            'min_change_pct' => $valid->count() ? round((float) $valid->min('change_pct'), 4) : null,
            'up_days' => $valid->filter(fn (object $row) => (float) $row->change_pct > 0)->count(),
            'down_days' => $valid->filter(fn (object $row) => (float) $row->change_pct < 0)->count(),
        ];
    }
}
