<?php

namespace App\Support;

use Illuminate\Support\Str;

class EventClusterDisplay
{
    public static function title(object $cluster): string
    {
        $themes = self::jsonList($cluster->themes ?? null);
        $category = (string) ($cluster->category ?? '');

        if (in_array('AI Server', $themes, true) || in_array('AI 伺服器', $themes, true)) {
            return 'AI 伺服器題材持續升溫';
        }

        if (in_array('雲端與資料中心', $themes, true)) {
            return '雲端與資料中心需求受關注';
        }

        if (in_array('Apple 生態系', $themes, true) || in_array('平台經濟', $themes, true)) {
            return 'Apple 與平台服務動態受關注';
        }

        if (in_array('航運運費', $themes, true)) {
            return '航運與貨櫃運價變化受關注';
        }

        if (in_array('貴金屬', $themes, true)) {
            return '黃金與避險資產動向受關注';
        }

        if (in_array('金融與利率', $themes, true) || $category === 'Fed') {
            return '利率與金融政策仍是市場焦點';
        }

        if (in_array('能源', $themes, true) || $category === 'Energy') {
            return '能源價格與供應變化受關注';
        }

        if (in_array('地緣政治', $themes, true) || $category === 'Geopolitics') {
            return '地緣政治風險需要追蹤';
        }

        return self::cleanTitle((string) $cluster->title);
    }

    public static function body(object $cluster): string
    {
        $themes = self::jsonList($cluster->themes ?? null);
        $summary = self::plainSummary((string) ($cluster->summary ?? ''), $themes, (string) ($cluster->category ?? ''));
        $sentiment = self::sentiment((string) ($cluster->sentiment ?? 'neutral'), $themes, $summary);

        return $summary
            .'｜市場解讀：'.$sentiment;
    }

    private static function plainSummary(string $summary, array $themes, string $category): string
    {
        $text = trim($summary);

        if (Str::contains($text, ['NVIDIA', 'AI', 'Google Cloud', 'GTC', 'COMPUTEX'])) {
            return 'AI 基礎建設、雲端服務與伺服器需求仍是市場關注主線，相關供應鏈熱度偏高。';
        }

        if (Str::contains($text, ['Federal Reserve', 'Fed', 'Commerce Bank']) || $category === 'Fed') {
            return '美國金融監管與利率政策消息持續影響市場風險偏好，短線需觀察資金面變化。';
        }

        if (Str::contains($text, ['App Store', 'GeForce NOW', 'stream'])) {
            return '大型科技平台服務持續擴張，市場會觀察訂閱、雲端與數位內容需求變化。';
        }

        if (Str::contains($text, ['Apple Sports', 'Sports expands', 'App Store stopped', 'fraudulent transactions'])) {
            return 'Apple 服務與平台治理消息增加，市場會觀察服務營收與生態系黏著度。';
        }

        if (Str::contains($text, ['pipeline', 'oil spill', 'oil leak'])) {
            return '能源基礎設施與供應消息增加，市場會觀察油價、成本與能源股反應。';
        }

        if (Str::contains($text, ['shipping', 'freight', 'container', 'Baltic Dry', 'Red Sea'])) {
            return '航運與貨櫃運價變化可能影響航運股、供應鏈成本與通膨預期。';
        }

        if (Str::contains($text, ['gold', 'precious metals', 'safe haven'])) {
            return '黃金與貴金屬動向反映避險需求、美元與利率預期變化。';
        }

        if (in_array('能源', $themes, true)) {
            return '能源與電力相關消息可能影響通膨、成本與高耗能產業評價。';
        }

        $clean = self::cleanTitle($text);

        return $clean === '' ? '事件仍在整理中，先列入觀察清單。' : $clean;
    }

    private static function cleanTitle(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = str_replace(['事件升溫, ', '事件升溫，', '事件升溫'], '', $text);
        $text = trim($text, " \t\n\r\0\x0B|,;；");

        return mb_substr($text, 0, 90);
    }

    private static function sentiment(string $sentiment, array $themes, string $summary): string
    {
        if (array_intersect($themes, ['AI Server', 'AI 伺服器', '雲端與資料中心', '半導體']) !== [] && str_contains($summary, '需求')) {
            return strtolower($sentiment) === 'negative'
                ? '偏正向，但留意外部風險'
                : '偏正向';
        }

        return match (strtolower($sentiment)) {
            'positive' => '偏正向',
            'negative' => '偏保守，需觀察風險',
            'neutral' => '中性觀察',
            default => '觀察中',
        };
    }

    private static function jsonList(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, fn ($value) => is_string($value) && $value !== '')) : [];
    }
}
