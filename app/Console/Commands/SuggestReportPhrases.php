<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SuggestReportPhrases extends Command
{
    protected $signature = 'market:agents-suggest-report-phrases
        {--date= : Suggestion date, default today in Asia/Taipei}
        {--limit=12 : Maximum suggestions to create}
        {--dry-run : Print suggestions without writing them}';

    protected $description = 'Let the learning agent suggest new stock report phrases for Codex review.';

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $limit = max(1, (int) $this->option('limit'));
        $roleId = DB::table('agent_roles')->where('slug', 'learning-recorder')->value('id');
        $startedAt = now();

        $runId = null;
        if (! $this->option('dry-run') && $roleId) {
            $runId = DB::table('agent_runs')->insertGetId([
                'agent_role_id' => $roleId,
                'run_key' => 'learning-recorder:phrase-suggestions:'.$date.':'.now('Asia/Taipei')->format('His'),
                'status' => 'running',
                'started_at' => $startedAt,
                'input_context' => json_encode([
                    'date' => $date,
                    'source' => 'stock reports, latest prices, technical indicators, chips, revenues, phrase usage',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $suggestions = collect($this->candidatePhrases($date))
            ->reject(fn (array $phrase) => $this->alreadyExists($phrase))
            ->take($limit)
            ->values();

        if ($this->option('dry-run')) {
            $suggestions->each(fn (array $phrase) => $this->line($phrase['section'].'/'.$phrase['condition_key'].'｜'.$phrase['template']));

            return self::SUCCESS;
        }

        $inserted = 0;
        foreach ($suggestions as $phrase) {
            DB::table('report_phrase_suggestions')->insert([
                'agent_role_id' => $roleId,
                'section' => $phrase['section'],
                'tone' => $phrase['tone'],
                'condition_key' => $phrase['condition_key'],
                'template' => $phrase['template'],
                'reason' => $phrase['reason'],
                'status' => 'pending',
                'metadata' => json_encode([
                    'suggested_by' => 'learning-recorder',
                    'suggestion_date' => $date,
                    'evidence' => $phrase['evidence'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        if ($runId) {
            DB::table('agent_runs')->where('id', $runId)->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => (int) round($startedAt->diffInMilliseconds(now())),
                'findings_count' => $inserted,
                'summary' => "學習紀錄員完成語句庫巡檢，新增 {$inserted} 筆待審語句建議。",
                'output_context' => json_encode([
                    'inserted_suggestions' => $inserted,
                    'pending_suggestions_total' => DB::table('report_phrase_suggestions')->where('status', 'pending')->count(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }

        $this->info("Report phrase suggestions created: {$inserted}");

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidatePhrases(string $date): array
    {
        $evidence = $this->marketEvidence($date);

        return [
            [
                'section' => 'price_theme',
                'tone' => 'bull',
                'condition_key' => 'price_up_volume_up',
                'template' => '{stock_name}今日不是單純跟漲，價格與量能同時放大，代表市場願意用更高成本承接；若這股力道能延續，短線評價會比單日反彈更有參考性。',
                'reason' => '補足「今日走勢＋量能」的白話敘述，避免每檔都只說題材支撐。',
                'evidence' => $evidence['price_volume'],
            ],
            [
                'section' => 'price_theme',
                'tone' => 'risk',
                'condition_key' => 'today_pullback_after_run',
                'template' => '{stock_name}近期漲幅已經先反映一段預期，今天若出現開高走低或量增不漲，就要把它視為籌碼開始鬆動的訊號，而不是單純震盪。',
                'reason' => '補足漲多後轉弱情境，避免風險股仍被寫成一般過熱。',
                'evidence' => $evidence['risk_cards'],
            ],
            [
                'section' => 'price_theme',
                'tone' => 'bull',
                'condition_key' => 'today_rebound_after_drop',
                'template' => '{stock_name}前段時間股價壓在低位整理，今天若能帶量站回短均線，重點會從「止跌」轉向「是否有資金重新進場」。',
                'reason' => '補足低檔反彈與低檔爆量的走勢語句。',
                'evidence' => $evidence['low_volume_cards'],
            ],
            [
                'section' => 'technical',
                'tone' => 'bull',
                'condition_key' => 'ma_bull',
                'template' => '短均線開始往上扣抵，且收盤價維持在均線之上，表示短線買盤還沒有被破壞；接下來要看量能是否能跟上，而不是只看一天紅 K。',
                'reason' => '讓均線多頭敘述更貼近使用者看到的 K 線狀態。',
                'evidence' => $evidence['technical'],
            ],
            [
                'section' => 'technical',
                'tone' => 'risk',
                'condition_key' => 'upper_shadow',
                'template' => 'K 線留下較長上影線時，代表盤中追價資金被上方賣壓壓回；若同時放量，這個位置就比較像換手壓力，而不是健康整理。',
                'reason' => '補足高檔震盪與上影線風險，對應使用者要求的高檔爆量轉弱。',
                'evidence' => $evidence['risk_cards'],
            ],
            [
                'section' => 'technical',
                'tone' => 'bear',
                'condition_key' => 'below_sma20',
                'template' => '股價仍壓在月線下方，代表短線反彈還沒有真正扭轉趨勢；若量能無法放大，容易變成弱勢中的技術性反抽。',
                'reason' => '補足下跌後反彈但尚未轉強的說法。',
                'evidence' => $evidence['weak_cards'],
            ],
            [
                'section' => 'chip',
                'tone' => 'bull',
                'condition_key' => 'foreign_trust_buy',
                'template' => '外資與投信若同步站在買方，代表不是只有短線資金在推動；這種籌碼結構通常比單一法人買超更有延續性。',
                'reason' => '增加法人同步買超的自然語句。',
                'evidence' => $evidence['chip'],
            ],
            [
                'section' => 'chip',
                'tone' => 'risk',
                'condition_key' => 'margin_high',
                'template' => '融資增加本身不是壞事，但若股價已經漲高又伴隨融資快速堆高，後續只要價格轉弱，賣壓會比一般股票更敏感。',
                'reason' => '補足融資風險的情境化解釋。',
                'evidence' => $evidence['chip'],
            ],
            [
                'section' => 'fundamental',
                'tone' => 'bull',
                'condition_key' => 'revenue_yoy_strong',
                'template' => '營收年增能跟上股價表現時，市場比較容易把上漲解讀成基本面支撐，而不是只有題材推升。',
                'reason' => '把營收與股價連動講清楚。',
                'evidence' => $evidence['fundamental'],
            ],
            [
                'section' => 'fundamental',
                'tone' => 'risk',
                'condition_key' => 'price_fundamental_gap',
                'template' => '如果股價已經先走一大段，但營收或獲利還沒有同步跟上，評價就容易變成靠預期支撐；這種股票最怕題材降溫或法人轉賣。',
                'reason' => '補足使用者要求的「股價高到與營收不符合」風險。',
                'evidence' => $evidence['risk_cards'],
            ],
            [
                'section' => 'summary',
                'tone' => 'neutral',
                'condition_key' => 'wait_for_confirmation',
                'template' => '整體來看，{stock_name}目前不能只用一天漲跌判斷，重點在於價格、量能、法人與營收是否同向；只要其中兩項不同步，解讀就要保留空間。',
                'reason' => '增加總評的整合句，降低機械式結論。',
                'evidence' => $evidence['summary'],
            ],
            [
                'section' => 'summary',
                'tone' => 'risk',
                'condition_key' => 'overall_risk',
                'template' => '{stock_name}目前比較需要注意的是「漲幅與支撐條件是否匹配」。若題材熱度還在，但量價或籌碼開始背離，後續波動通常會放大。',
                'reason' => '讓總評能描述風險股，不再只說過熱。',
                'evidence' => $evidence['risk_cards'],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function marketEvidence(string $date): array
    {
        $latestCardDate = DB::table('stock_radar_cards')->max('card_date');
        $cards = $latestCardDate
            ? DB::table('stock_radar_cards')->where('card_date', $latestCardDate)->selectRaw('card_type, count(*) as count')->groupBy('card_type')->pluck('count', 'card_type')->all()
            : [];
        $latestPriceDate = DB::table('stock_prices_1d')->max('trade_date');
        $latestTechnicalDate = DB::table('stock_technical_indicators_1d')->max('trade_date');
        $latestChipDate = DB::table('stock_chips_1d')->max('trade_date');
        $latestRevenueMonth = DB::table('stock_revenues')->max('year_month');
        $topUsed = DB::table('report_phrases')->where('status', 'active')->orderByDesc('usage_count')->limit(5)->pluck('usage_count', 'condition_key')->all();

        $base = [
            'suggestion_date' => $date,
            'latest_card_date' => $latestCardDate,
            'card_counts' => $cards,
            'top_used_conditions' => $topUsed,
        ];

        return [
            'price_volume' => $base + ['latest_price_date' => $latestPriceDate],
            'risk_cards' => $base + ['risk_cards' => $cards['risk'] ?? 0],
            'low_volume_cards' => $base + ['low_volume_cards' => $cards['low_volume'] ?? 0],
            'weak_cards' => $base + ['weak_cards' => $cards['weak'] ?? 0],
            'technical' => $base + ['latest_technical_date' => $latestTechnicalDate],
            'chip' => $base + ['latest_chip_date' => $latestChipDate],
            'fundamental' => $base + ['latest_revenue_month' => $latestRevenueMonth],
            'summary' => $base + compact('latestPriceDate', 'latestTechnicalDate', 'latestChipDate', 'latestRevenueMonth'),
        ];
    }

    /**
     * @param array<string,mixed> $phrase
     */
    private function alreadyExists(array $phrase): bool
    {
        $template = (string) $phrase['template'];

        if (DB::table('report_phrases')->where('template', $template)->exists()) {
            return true;
        }

        return DB::table('report_phrase_suggestions')
            ->where('template', $template)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
    }
}
