<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateGlobalInfluenceScores extends Command
{
    protected $signature = 'market:calculate-global-influence {--date= : Score date, default today}';

    protected $description = 'Calculate macro and event scores from imported global market/event data.';

    public function handle(): int
    {
        $scoreDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $macroScore = $this->macroScore();
        $eventScore = $this->eventScore();

        if ($macroScore === null && $eventScore === null) {
            $this->warn('Skipped: no global market or event data available.');
            return self::SUCCESS;
        }

        $updated = 0;

        Stock::query()->where('is_active', true)->with('latestScore')->chunkById(500, function ($stocks) use ($scoreDate, $macroScore, $eventScore, &$updated) {
            foreach ($stocks as $stock) {
                $score = $stock->latestScore;

                if (! $score) {
                    continue;
                }

                $score->fill([
                    'score_date' => $score->score_date ?: $scoreDate,
                    'macro_score' => $macroScore,
                    'event_score' => $eventScore,
                    'sentiment_score' => $this->sentimentScore($macroScore, $eventScore),
                ])->save();

                $updated++;
            }
        });

        $this->info('Global influence updated: '.$updated);
        $this->line('Macro score: '.($macroScore ?? 'n/a'));
        $this->line('Event score: '.($eventScore ?? 'n/a'));

        return self::SUCCESS;
    }

    private function macroScore(): ?int
    {
        $latestDate = DB::table('global_market_data')->max('trade_date');

        if (! $latestDate) {
            return null;
        }

        $rows = DB::table('global_market_data')->where('trade_date', $latestDate)->get()->keyBy('indicator');

        if ($rows->isEmpty()) {
            return null;
        }

        $score = 50;

        foreach (['S&P 500' => 9, 'NASDAQ' => 12, 'SOX' => 16, 'TSM ADR' => 10] as $name => $weight) {
            $change = isset($rows[$name]) ? (float) ($rows[$name]->change_pct ?? 0) : null;

            if ($change !== null) {
                $score += max(-$weight, min($weight, $change * ($weight / 1.5)));
            }
        }

        if (isset($rows['VIX'])) {
            $vix = (float) $rows['VIX']->value;
            $score += match (true) {
                $vix < 16 => 8,
                $vix < 22 => 0,
                default => -12,
            };
        }

        foreach (['US10Y' => 6, 'DXY' => 5] as $name => $weight) {
            if (isset($rows[$name]) && $rows[$name]->change_pct !== null) {
                $score -= max(-$weight, min($weight, (float) $rows[$name]->change_pct * ($weight / 1.0)));
            }
        }

        return max(0, min(100, (int) round($score)));
    }

    private function eventScore(): ?int
    {
        $recentEvents = DB::table('global_events')
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        if ($recentEvents->isEmpty()) {
            return null;
        }

        $score = 50;
        $aiCount = $recentEvents->where('category', 'AI')->count();
        $fedCount = $recentEvents->where('category', 'Fed')->count();
        $geoCount = $recentEvents->where('category', 'Geopolitics')->count();

        $score += min(18, $aiCount * 3);
        $score += min(8, $fedCount * 1);
        $score -= min(14, $geoCount * 4);

        return max(0, min(100, (int) round($score)));
    }

    private function sentimentScore(?int $macroScore, ?int $eventScore): ?int
    {
        $scores = collect([$macroScore, $eventScore])->filter(fn ($score) => $score !== null);

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }
}
