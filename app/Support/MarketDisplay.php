<?php

namespace App\Support;

class MarketDisplay
{
    public static function indicatorName(string $indicator): string
    {
        return [
            'S&P 500' => '標普 500',
            'NASDAQ' => '那斯達克',
            'SOX' => '費城半導體',
            'VIX' => 'VIX 恐慌指數',
            'DXY' => '美元指數',
            'US10Y' => '美國十年債殖利率',
            'Crude Oil' => '原油',
            'Gold' => '黃金',
            'TSM ADR' => '台積電 ADR',
        ][$indicator] ?? $indicator;
    }

    public static function stateName(?string $state): string
    {
        return [
            'strong' => '強勢',
            'positive' => '偏強',
            'soft' => '偏弱',
            'weak' => '弱勢',
            'low_risk' => '低風險',
            'neutral' => '中性',
            'high_risk' => '高風險',
            'pressure_down' => '壓力下降',
            'pressure_up' => '壓力上升',
            'unknown' => '待判讀',
        ][$state ?? 'unknown'] ?? '待判讀';
    }

    public static function tone(?string $state, ?float $changePct = null): string
    {
        if (in_array($state, ['strong', 'positive', 'low_risk', 'pressure_down'], true)) {
            return 'green';
        }

        if (in_array($state, ['weak', 'high_risk', 'pressure_up'], true)) {
            return 'red';
        }

        return $changePct !== null && $changePct >= 0 ? 'green' : 'amber';
    }

    public static function eventTitle(object $event): string
    {
        $source = $event->source ?: '全球消息';
        $category = self::categoryName($event->category ?: 'Global');

        return match ($source) {
            'Federal Reserve' => '聯準會最新公告',
            'NVIDIA Blog' => '輝達人工智慧與運算更新',
            'Apple Newsroom' => '蘋果產品與服務消息',
            'Microsoft Blog' => '微軟人工智慧與雲端更新',
            default => $category.'事件',
        };
    }

    public static function eventBody(object $event): string
    {
        $date = $event->event_date ? date('Y-m-d H:i', strtotime($event->event_date)) : '日期待補';
        $category = self::categoryName($event->category ?: 'Global');
        $impact = $event->impact_score === null ? '待 AI 判讀' : $event->impact_score.'/100';

        return '日期：'.$date.'｜分類：'.$category.'｜影響分數：'.$impact.'｜來源已收錄，中文摘要待 AI 解釋引擎產生。';
    }

    public static function categoryName(string $category): string
    {
        return [
            'Fed' => '聯準會',
            'AI' => '人工智慧',
            'Geopolitics' => '地緣政治',
            'Apple' => '蘋果供應鏈',
            'Microsoft' => '微軟雲端',
            'Global' => '全球事件',
        ][$category] ?? $category;
    }
}
