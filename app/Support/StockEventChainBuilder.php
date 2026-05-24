<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;

class StockEventChainBuilder
{
    public function build(Stock $stock, mixed $score): array
    {
        $stockThemes = DB::table('stock_theme_map')
            ->join('themes', 'themes.id', '=', 'stock_theme_map.theme_id')
            ->where('stock_theme_map.stock_id', $stock->id)
            ->pluck('themes.name')
            ->map(fn ($name) => $this->normalizeTheme((string) $name))
            ->unique()
            ->values()
            ->all();

        if ($stockThemes === []) {
            return [];
        }

        $events = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit(10)
            ->get();

        $chains = [];

        foreach ($events as $event) {
            $eventThemes = collect(json_decode((string) $event->themes, true) ?: [])
                ->map(fn ($theme) => $this->normalizeTheme((string) $theme))
                ->unique()
                ->values()
                ->all();
            $matched = array_values(array_intersect($stockThemes, $eventThemes));

            if ($matched === []) {
                continue;
            }

            $chains[] = [
                'event' => EventClusterDisplay::title($event),
                'path' => EventClusterDisplay::title($event).' → '.implode(' / ', $matched).' → '.$stock->name,
                'judgement' => $this->judgement($event, $score, $matched),
            ];

            if (count($chains) >= 3) {
                break;
            }
        }

        return $chains;
    }

    private function normalizeTheme(string $theme): string
    {
        return match (true) {
            str_contains($theme, 'AI') || str_contains($theme, '伺服器') => 'AI Server',
            str_contains($theme, '半導體') || str_contains($theme, 'CoWoS') || str_contains($theme, '封裝') => '半導體',
            str_contains($theme, '雲端') || str_contains($theme, '資料中心') => '雲端與資料中心',
            str_contains($theme, '能源') || str_contains($theme, '油') => '能源',
            str_contains($theme, '航運') || str_contains($theme, '運費') => '航運運費',
            str_contains($theme, '金融') || str_contains($theme, '利率') => '金融與利率',
            str_contains($theme, '地緣') => '地緣政治',
            default => $theme,
        };
    }

    private function judgement(object $event, mixed $score, array $matched): string
    {
        $themeText = implode('、', $matched);
        $sentiment = (string) ($event->sentiment ?? 'neutral');

        if ($sentiment === 'negative') {
            return $themeText.'受到事件牽動，但市場風險偏高，需搭配技術與籌碼確認。';
        }

        if (($score?->theme_score ?? 0) >= 65 && ($score?->technical_score ?? 0) >= 60) {
            return $themeText.'與個股分數同向，短線題材有支撐。';
        }

        if (($score?->technical_score ?? 0) < 45 || ($score?->chip_score ?? 0) < 45) {
            return $themeText.'有事件關聯，但個股技術或籌碼未同步轉強，先觀察。';
        }

        return $themeText.'有事件關聯，影響偏觀察，需看後續分數是否延續。';
    }
}
