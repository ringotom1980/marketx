<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStockReports extends Command
{
    protected $signature = 'market:generate-stock-reports
        {--limit=0 : Max reports to generate. Use 0 for all scored stocks}
        {--date= : Report date, default today}';

    protected $description = 'Generate free rule-based Chinese explanation reports from existing MarketX scores.';

    public function handle(): int
    {
        $reportDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $limit = max(0, (int) $this->option('limit'));
        $generated = 0;

        $query = Stock::query()
            ->with(['latestScore', 'latestChip', 'dailyPrices' => fn ($query) => $query->latest('trade_date')->limit(1)])
            ->whereHas('latestScore', fn ($query) => $query->whereNotNull('total_score'))
            ->orderBy('symbol');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->get()->each(function (Stock $stock) use ($reportDate, &$generated) {
            $score = $stock->latestScore;
            $chip = $stock->latestChip;
            $price = $stock->dailyPrices->first();
            $revenue = DB::table('stock_revenues')
                ->where('stock_id', $stock->id)
                ->orderByDesc('year_month')
                ->first();

            $dataPack = [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'decision' => $score->decision,
                'total_score' => $score->total_score,
                'confidence_score' => $score->confidence_score,
                'macro_score' => $score->macro_score,
                'event_score' => $score->event_score,
                'theme_score' => $score->theme_score,
                'technical_score' => $score->technical_score,
                'chip_score' => $score->chip_score,
                'fundamental_score' => $score->fundamental_score,
                'sentiment_score' => $score->sentiment_score,
                'latest_close' => $price?->close,
                'latest_volume' => $price?->volume,
                'foreign_net_buy' => $chip?->foreign_net_buy,
                'investment_trust_net_buy' => $chip?->investment_trust_net_buy,
                'margin_balance' => $chip?->margin_balance,
                'short_balance' => $chip?->short_balance,
                'revenue_year_month' => $revenue?->year_month,
                'revenue_yoy_pct' => $revenue?->yoy_pct,
                'engine' => 'rule_based_zh',
            ];

            DB::table('stock_reports')->updateOrInsert(
                ['stock_id' => $stock->id, 'report_date' => $reportDate],
                [
                    'decision' => $score->decision,
                    'summary' => $this->summary($stock, $score, $chip, $revenue),
                    'bull_case' => $this->bullCase($score, $chip, $revenue),
                    'bear_case' => $this->bearCase($score, $chip, $revenue),
                    'risk_summary' => $this->riskSummary($score, $chip, $revenue),
                    'data_pack' => json_encode($dataPack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'model' => 'rule-based-zh',
                    'token_usage' => json_encode(['prompt_tokens' => 0, 'completion_tokens' => 0]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $generated++;
        });

        DB::table('ai_logs')->insert([
            'task' => 'stock_report_generation',
            'model' => 'rule-based-zh',
            'input_hash' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_estimate' => 0,
            'status' => 'success_rule_based',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Rule-based Chinese reports generated: '.$generated);

        return self::SUCCESS;
    }

    private function summary(Stock $stock, mixed $score, mixed $chip, mixed $revenue): string
    {
        $parts = [
            $stock->name.'目前決策為「'.$score->decision.'」，總分 '.$score->total_score.' / 100，信心度 '.$score->confidence_score.'%。',
        ];

        $drivers = $this->drivers($score);

        if ($drivers !== []) {
            $parts[] = '主要支撐來自'.$this->joinZh($drivers).'。';
        }

        $chipText = $this->chipText($chip);

        if ($chipText !== null) {
            $parts[] = $chipText;
        }

        if ($revenue?->yoy_pct !== null) {
            $parts[] = '最新月營收年增率為 '.number_format((float) $revenue->yoy_pct, 2).'%，財務營收分數 '.$score->fundamental_score.'。';
        }

        return implode('', $parts);
    }

    private function bullCase(mixed $score, mixed $chip, mixed $revenue): string
    {
        $points = [];

        if (($score->technical_score ?? 0) >= 70) {
            $points[] = '技術結構偏多';
        }

        if (($score->chip_score ?? 0) >= 65) {
            $points[] = '法人籌碼偏正向';
        }

        if (($score->macro_score ?? 0) >= 65) {
            $points[] = '全球市場環境偏有利';
        }

        if (($score->event_score ?? 0) >= 65) {
            $points[] = '全球事件對科技與台股風險偏正向';
        }

        if (($score->theme_score ?? 0) >= 60) {
            $points[] = '所屬題材仍有熱度';
        }

        if ($revenue?->yoy_pct !== null && (float) $revenue->yoy_pct > 10) {
            $points[] = '月營收成長動能明顯';
        }

        return $points === []
            ? '目前沒有特別突出的單一加分項，較適合觀察分數是否連續改善。'
            : '偏多理由：'.$this->joinZh($points).'。';
    }

    private function bearCase(mixed $score, mixed $chip, mixed $revenue): string
    {
        $points = [];

        if (($score->technical_score ?? 100) < 50) {
            $points[] = '技術結構偏弱';
        }

        if (($score->chip_score ?? 100) < 45) {
            $points[] = '法人籌碼偏保守';
        }

        if (($score->fundamental_score ?? 100) < 45) {
            $points[] = '營收或財務分數偏低';
        }

        if (($score->macro_score ?? 100) < 50) {
            $points[] = '全球市場壓力較高';
        }

        if ($revenue?->yoy_pct !== null && (float) $revenue->yoy_pct < 0) {
            $points[] = '月營收年增率轉弱';
        }

        if ($chip && $chip->foreign_net_buy < 0 && $chip->investment_trust_net_buy < 0) {
            $points[] = '外資與投信同步賣超';
        }

        return $points === []
            ? '目前主要風險不明顯，但仍需留意高檔震盪、量價失衡或法人轉賣。'
            : '偏空風險：'.$this->joinZh($points).'。';
    }

    private function riskSummary(mixed $score, mixed $chip, mixed $revenue): string
    {
        if (($score->total_score ?? 0) >= 85) {
            return '分數已進入強勢區，若短線漲多或放量不漲，容易出現震盪。';
        }

        if (($score->total_score ?? 0) < 40) {
            return '分數位於弱勢區，除非技術、籌碼或營收同步改善，否則不宜過度積極。';
        }

        if ($chip && $chip->institutional_net_buy < 0) {
            return '法人合計賣超，需觀察是否連續轉弱。';
        }

        if ($chip && $chip->margin_balance !== null && $chip->short_balance !== null && $chip->short_balance > $chip->margin_balance * 0.6) {
            return '融券餘額相對融資偏高，短線籌碼波動可能加大。';
        }

        if ($revenue?->yoy_pct !== null && (float) $revenue->yoy_pct < 0) {
            return '營收年增率為負，需留意基本面動能是否下修。';
        }

        return '目前風險屬中性，重點觀察分數是否連續上升或跌破關鍵均線。';
    }

    private function drivers(mixed $score): array
    {
        $labels = [
            'macro_score' => '全球宏觀',
            'event_score' => '全球事件',
            'theme_score' => '題材熱度',
            'technical_score' => '技術結構',
            'chip_score' => '籌碼',
            'fundamental_score' => '財務營收',
        ];

        $drivers = [];

        foreach ($labels as $field => $label) {
            if (($score->{$field} ?? null) !== null && $score->{$field} >= 65) {
                $drivers[] = $label.' '.$score->{$field};
            }
        }

        return $drivers;
    }

    private function chipText(mixed $chip): ?string
    {
        if (! $chip) {
            return null;
        }

        if ($chip->foreign_net_buy > 0 && $chip->investment_trust_net_buy > 0) {
            return '籌碼面外資與投信同步買超，短線資金態度偏正向。';
        }

        if ($chip->foreign_net_buy < 0 && $chip->investment_trust_net_buy < 0) {
            return '籌碼面外資與投信同步賣超，需留意法人態度轉弱。';
        }

        if ($chip->institutional_net_buy > 0) {
            return '三大法人合計買超，籌碼面偏有支撐。';
        }

        if ($chip->institutional_net_buy < 0) {
            return '三大法人合計賣超，籌碼面偏保守。';
        }

        return '法人籌碼變化不明顯，暫以技術與營收分數輔助判斷。';
    }

    private function joinZh(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode('、', $items).'與'.$last;
    }
}
