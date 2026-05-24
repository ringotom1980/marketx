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
                ->where('theme_id', $theme->id)
                ->where('created_at', '>=', CarbonImmutable::parse($scoreDate, 'Asia/Taipei')->subDays(7))
                ->get();

            if ($mapped->isEmpty() && $eventMatches->isEmpty()) {
                $skipped++;
                return;
            }

            $scores = $mapped->isEmpty()
                ? collect()
                : DB::table('stock_scores')
                    ->select('stock_id', 'technical_score', 'chip_score', 'total_score')
                    ->whereIn('stock_id', $mapped)
                    ->whereNotNull('technical_score')
                    ->orderByDesc('score_date')
                    ->get()
                    ->unique('stock_id');

            $priceScore = $scores->isEmpty() ? null : (int) round($scores->avg('technical_score'));
            $chipScore = $scores->isEmpty() ? null : (int) round($scores->avg('chip_score'));
            $newsScore = $eventMatches->isEmpty()
                ? null
                : max(0, min(100, (int) round(min(100, $eventMatches->sum('match_score') / 3))));
            $heatScore = (int) round(collect([
                $priceScore,
                $chipScore ?: null,
                $newsScore,
            ])->filter(fn ($value) => $value !== null)->avg());

            DB::table('theme_scores')->updateOrInsert(
                ['theme_id' => $theme->id, 'score_date' => $scoreDate],
                [
                    'heat_score' => max(0, min(100, $heatScore)),
                    'news_score' => $newsScore,
                    'price_score' => $priceScore === null ? null : max(0, min(100, $priceScore)),
                    'chip_score' => $chipScore ? max(0, min(100, $chipScore)) : null,
                    'payload' => json_encode([
                        'mapped_stock_count' => $mapped->count(),
                        'scored_stock_count' => $scores->count(),
                        'event_match_count' => $eventMatches->count(),
                        'source' => 'theme_event_matches + stock_theme_map + stock_scores',
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
