<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateThemeScores extends Command
{
    protected $signature = 'market:calculate-theme-scores {--date= : Score date, default today}';

    protected $description = 'Calculate theme heat scores from mapped stocks and existing module scores.';

    public function handle(): int
    {
        $scoreDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $calculated = 0;
        $skipped = 0;

        Theme::query()->where('is_active', true)->orderBy('id')->each(function (Theme $theme) use ($scoreDate, &$calculated, &$skipped) {
            $mapped = DB::table('stock_theme_map')
                ->where('theme_id', $theme->id)
                ->pluck('stock_id');

            $eventMatches = DB::table('theme_event_matches')
                ->leftJoin('global_events', 'global_events.id', '=', 'theme_event_matches.global_event_id')
                ->where('theme_event_matches.theme_id', $theme->id)
                ->where('theme_event_matches.created_at', '>=', CarbonImmutable::parse($scoreDate, 'Asia/Taipei')->subDays(7))
                ->get([
                    'theme_event_matches.match_score',
                    'theme_event_matches.created_at',
                    'global_events.event_date',
                    'global_events.region',
                    'global_events.source',
                ]);

            if ($mapped->isEmpty() && $eventMatches->isEmpty()) {
                $skipped++;
                return;
            }

            $scores = $mapped->isEmpty()
                ? collect()
                : DB::table('stock_scores')
                    ->select('stock_id', 'technical_score', 'chip_score', 'total_score', 'confidence_score')
                    ->whereIn('stock_id', $mapped)
                    ->whereNotNull('technical_score')
                    ->orderByDesc('score_date')
                    ->get()
                    ->unique('stock_id');

            $latestTechnicalRows = $mapped->isEmpty()
                ? collect()
                : DB::table('stock_technical_indicators_1d')
                    ->select('stock_id', 'return20', 'volume_ratio20')
                    ->whereIn('stock_id', $mapped)
                    ->orderByDesc('trade_date')
                    ->get()
                    ->unique('stock_id');

            $newsScore = $this->newsScore($eventMatches, $scoreDate);
            $stockScore = $this->stockScore($scores, $latestTechnicalRows);
            $flowScore = $this->flowScore($scores, $latestTechnicalRows);
            $freshnessScore = $this->freshnessScore($eventMatches, $scoreDate);
            $heatScore = (int) round(
                ($newsScore * 0.45)
                + ($stockScore * 0.30)
                + ($flowScore * 0.15)
                + ($freshnessScore * 0.10)
            );

            DB::table('theme_scores')->updateOrInsert(
                ['theme_id' => $theme->id, 'score_date' => $scoreDate],
                [
                    'heat_score' => max(0, min(100, $heatScore)),
                    'news_score' => $newsScore,
                    'price_score' => $stockScore,
                    'volume_score' => $flowScore,
                    'chip_score' => $scores->isEmpty() ? null : max(0, min(100, (int) round($scores->avg('chip_score')))),
                    'payload' => json_encode([
                        'mapped_stock_count' => $mapped->count(),
                        'scored_stock_count' => $scores->count(),
                        'event_match_count' => $eventMatches->count(),
                        'formula' => [
                            'news_heat' => 45,
                            'stock_performance' => 30,
                            'volume_and_chip_flow' => 15,
                            'freshness_decay' => 10,
                        ],
                        'news_score' => $newsScore,
                        'stock_score' => $stockScore,
                        'flow_score' => $flowScore,
                        'freshness_score' => $freshnessScore,
                        'source' => 'theme_event_matches + global_events + stock_theme_map + stock_scores + stock_technical_indicators_1d',
                    ], JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $calculated++;
        });

        $stockScoresUpdated = $this->updateStockThemeScores();

        $this->info('Theme scores calculated: '.$calculated);
        $this->line('Skipped without mapped/scored stocks: '.$skipped);
        $this->line('Stock theme scores updated: '.$stockScoresUpdated);

        return self::SUCCESS;
    }

    private function newsScore(\Illuminate\Support\Collection $eventMatches, string $scoreDate): int
    {
        if ($eventMatches->isEmpty()) {
            return 0;
        }

        $scoreDate = CarbonImmutable::parse($scoreDate, 'Asia/Taipei');
        $weighted = $eventMatches->sum(function ($event) use ($scoreDate) {
            $eventDate = $event->event_date
                ? CarbonImmutable::parse($event->event_date, 'Asia/Taipei')
                : CarbonImmutable::parse($event->created_at, 'Asia/Taipei');
            $days = max(0, $eventDate->diffInDays($scoreDate, false));
            $decay = max(0.25, 1 - ($days * 0.12));
            $regionWeight = $this->isTaiwanEvent($event) ? 1.25 : 0.75;

            return ((int) $event->match_score) * $decay * $regionWeight;
        });

        return max(0, min(100, (int) round($weighted / 3.2)));
    }

    private function stockScore(\Illuminate\Support\Collection $scores, \Illuminate\Support\Collection $technicalRows): int
    {
        if ($scores->isEmpty()) {
            return 0;
        }

        $technicalByStock = $technicalRows->keyBy('stock_id');
        $base = $scores->avg(fn ($row) => collect([
            $row->confidence_score,
            $row->technical_score,
            $row->total_score,
        ])->filter(fn ($value) => $value !== null)->avg());
        $momentumBonus = $scores->avg(function ($row) use ($technicalByStock) {
            $technical = $technicalByStock[$row->stock_id] ?? null;
            $return20 = $technical?->return20 === null ? 0 : (float) $technical->return20;

            return match (true) {
                $return20 >= 12 => 10,
                $return20 >= 5 => 6,
                $return20 > 0 => 3,
                $return20 <= -8 => -8,
                $return20 < 0 => -3,
                default => 0,
            };
        });

        return max(0, min(100, (int) round($base + $momentumBonus)));
    }

    private function flowScore(\Illuminate\Support\Collection $scores, \Illuminate\Support\Collection $technicalRows): int
    {
        if ($scores->isEmpty() && $technicalRows->isEmpty()) {
            return 0;
        }

        $chip = $scores->isEmpty() ? 50 : (float) $scores->avg('chip_score');
        $volume = $technicalRows->isEmpty()
            ? 50
            : (float) $technicalRows->avg(function ($row) {
                $ratio = (float) ($row->volume_ratio20 ?? 0);

                return match (true) {
                    $ratio >= 2.5 => 95,
                    $ratio >= 1.8 => 82,
                    $ratio >= 1.2 => 68,
                    $ratio >= 0.8 => 50,
                    default => 38,
                };
            });

        return max(0, min(100, (int) round(($chip * 0.55) + ($volume * 0.45))));
    }

    private function freshnessScore(\Illuminate\Support\Collection $eventMatches, string $scoreDate): int
    {
        if ($eventMatches->isEmpty()) {
            return 20;
        }

        $scoreDate = CarbonImmutable::parse($scoreDate, 'Asia/Taipei');
        $latest = $eventMatches
            ->map(fn ($event) => $event->event_date ?: $event->created_at)
            ->filter()
            ->map(fn ($date) => CarbonImmutable::parse($date, 'Asia/Taipei'))
            ->max();

        if (! $latest) {
            return 20;
        }

        $days = max(0, $latest->diffInDays($scoreDate, false));

        return match (true) {
            $days <= 1 => 100,
            $days <= 2 => 82,
            $days <= 4 => 62,
            $days <= 7 => 42,
            default => 20,
        };
    }

    private function isTaiwanEvent(object $event): bool
    {
        $region = strtolower((string) ($event->region ?? ''));
        $source = strtolower((string) ($event->source ?? ''));

        return in_array($region, ['tw', 'taiwan', '台灣'], true)
            || str_contains($source, 'taiwan')
            || str_contains($source, 'twse')
            || str_contains($source, 'tpex')
            || str_contains($source, '台灣');
    }

    private function updateStockThemeScores(): int
    {
        $latestThemeScores = DB::table('theme_scores')
            ->select('theme_scores.theme_id', 'theme_scores.heat_score')
            ->whereNotNull('theme_scores.heat_score')
            ->orderByDesc('theme_scores.score_date')
            ->orderByDesc('theme_scores.id')
            ->get()
            ->unique('theme_id')
            ->keyBy('theme_id');

        if ($latestThemeScores->isEmpty()) {
            return 0;
        }

        $updated = 0;

        DB::table('stock_theme_map')
            ->orderBy('stock_id')
            ->get()
            ->groupBy('stock_id')
            ->each(function ($maps, int $stockId) use ($latestThemeScores, &$updated) {
                $weighted = [];

                foreach ($maps as $map) {
                    $themeScore = $latestThemeScores[$map->theme_id]->heat_score ?? null;

                    if ($themeScore !== null) {
                        $weighted[] = ['score' => (int) $themeScore, 'weight' => max(1, (int) $map->weight)];
                    }
                }

                if ($weighted === []) {
                    return;
                }

                $weightSum = array_sum(array_column($weighted, 'weight'));
                $score = (int) round(array_sum(array_map(fn ($row) => $row['score'] * $row['weight'], $weighted)) / $weightSum);

                $latestScoreId = DB::table('stock_scores')
                    ->where('stock_id', $stockId)
                    ->orderByDesc('score_date')
                    ->orderByDesc('id')
                    ->value('id');

                if (! $latestScoreId) {
                    return;
                }

                $updated += DB::table('stock_scores')
                    ->where('id', $latestScoreId)
                    ->update(['theme_score' => max(0, min(100, $score)), 'updated_at' => now()]);
            });

        return $updated;
    }
}
