<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentMemory;
use App\Models\AgentRole;
use App\Models\AgentRun;
use App\Models\MarketDailyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RunAgentInspections extends Command
{
    protected $signature = 'market:agents-run
        {--date= : Inspection date, default today in Asia/Taipei}
        {--agent=* : Limit to agent slugs: data-quality, home-radar, stock-consistency, theme-radar, global-radar}';

    protected $description = 'Run rule-based MarketX agent inspections and write findings to the agent communication tables.';

    private string $inspectionDate;
    private ?MarketDailyContext $marketContext = null;

    public function handle(): int
    {
        $this->inspectionDate = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $this->marketContext = $this->loadMarketContext();

        $this->ensureBaseMemories();

        $agents = collect($this->option('agent'))
            ->filter()
            ->values();

        $available = [
            'data-quality' => fn () => $this->runDataQualityAgent(),
            'home-radar' => fn () => $this->runHomeRadarAgent(),
            'stock-consistency' => fn () => $this->runStockConsistencyAgent(),
            'theme-radar' => fn () => $this->runThemeRadarAgent(),
            'global-radar' => fn () => $this->runGlobalRadarAgent(),
        ];

        foreach ($available as $slug => $runner) {
            if ($agents->isNotEmpty() && ! $agents->contains($slug)) {
                continue;
            }

            $runner();
        }

        $this->info('Agent inspections completed for '.$this->inspectionDate);

        return self::SUCCESS;
    }

    private function runDataQualityAgent(): void
    {
        $role = $this->role('data-quality');
        $run = $this->startRun($role);
        $findings = 0;

        try {
            $activeStocks = (int) DB::table('stocks')->where('is_active', true)->count();
            $latestPriceDate = DB::table('stock_prices_1d')->max('trade_date');
            $latestScoreDate = DB::table('stock_scores')->max('score_date');
            $latestTechnicalDate = DB::table('stock_technical_indicators_1d')->max('trade_date');
            $latestChipDate = DB::table('stock_chips_1d')->max('trade_date');
            $latestGlobalDate = DB::table('global_market_data')->max('trade_date');

            if (! $this->marketContext) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'market_context_missing',
                    'page' => 'admin',
                    'title' => '每日市場背景包尚未建立',
                    'description' => '代理人巡查時找不到 market_daily_contexts。這會讓各代理人缺少共同市場背景，後續案件較難追溯判斷依據。',
                    'evidence' => "inspection_date={$this->inspectionDate}",
                    'recommendation' => '執行 market:build-daily-context，並確認盤前、盤後、夜盤與凌晨排程都有成功建立背景包。',
                    'payload' => ['inspection_date' => $this->inspectionDate],
                ]);
            }

            $priceCount = $latestPriceDate
                ? (int) DB::table('stock_prices_1d')->where('trade_date', $latestPriceDate)->count()
                : 0;
            $scoreCount = $latestScoreDate
                ? (int) DB::table('stock_scores')->where('score_date', $latestScoreDate)->count()
                : 0;
            $technicalCount = $latestTechnicalDate
                ? (int) DB::table('stock_technical_indicators_1d')->where('trade_date', $latestTechnicalDate)->count()
                : 0;
            $chipCount = $latestChipDate
                ? (int) DB::table('stock_chips_1d')->where('trade_date', $latestChipDate)->count()
                : 0;

            $minimumBroadCoverage = max(1, (int) floor($activeStocks * 0.88));

            if (! $latestPriceDate || $priceCount < $minimumBroadCoverage) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'data_coverage',
                    'page' => 'home',
                    'title' => '台股價格資料覆蓋不足',
                    'description' => '最新日 K 覆蓋檔數低於 active stocks 的 88%，首頁分類與個股評價可能失真。',
                    'evidence' => "active={$activeStocks}, latest_price_date={$latestPriceDate}, price_count={$priceCount}, minimum={$minimumBroadCoverage}",
                    'recommendation' => '檢查台股價格匯入排程與 TWSE/TPEx 抓取結果，必要時補跑 price pipeline。',
                    'payload' => compact('activeStocks', 'latestPriceDate', 'priceCount', 'minimumBroadCoverage'),
                ]);
            }

            if ($latestPriceDate && $latestTechnicalDate !== $latestPriceDate) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'data_staleness',
                    'page' => 'home',
                    'title' => '技術指標日期未跟上價格資料',
                    'description' => '最新技術指標日期與最新價格日期不同，首頁五張卡與個股 K 線文字分析可能不同步。',
                    'evidence' => "latest_price_date={$latestPriceDate}, latest_technical_date={$latestTechnicalDate}",
                    'recommendation' => '價格匯入後應補跑 technical score 與 radar cards。',
                    'payload' => compact('latestPriceDate', 'latestTechnicalDate', 'technicalCount'),
                ]);
            }

            if ($latestScoreDate && $scoreCount < $minimumBroadCoverage) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'data_coverage',
                    'page' => 'home',
                    'title' => '個股信心分數覆蓋不足',
                    'description' => '最新 stock_scores 檔數偏少，分類卡可能只從部分股票挑選。',
                    'evidence' => "latest_score_date={$latestScoreDate}, score_count={$scoreCount}, minimum={$minimumBroadCoverage}",
                    'recommendation' => '補跑 decision score 與 radar cards，確認所有 active stocks 都完成評分。',
                    'payload' => compact('latestScoreDate', 'scoreCount', 'minimumBroadCoverage'),
                ]);
            }

            if ($latestPriceDate && $technicalCount < (int) floor($priceCount * 0.9)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'data_coverage',
                    'page' => 'home',
                    'title' => '技術指標覆蓋不足',
                    'description' => '最新技術指標筆數明顯少於最新日 K 筆數，部分股票分類可能缺少技術依據。',
                    'evidence' => "price_count={$priceCount}, technical_count={$technicalCount}",
                    'recommendation' => '檢查 technical indicator 計算是否因歷史資料不足或例外中斷。',
                    'payload' => compact('priceCount', 'technicalCount', 'latestTechnicalDate'),
                ]);
            }

            if ($latestChipDate && $chipCount < (int) floor($activeStocks * 0.55)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'data_coverage',
                    'page' => 'stock',
                    'title' => '籌碼資料覆蓋偏低',
                    'description' => '籌碼資料覆蓋不到 active stocks 的 55%，個股籌碼與信心分數需標記資料限制。',
                    'evidence' => "latest_chip_date={$latestChipDate}, chip_count={$chipCount}, active={$activeStocks}",
                    'recommendation' => '確認法人、融資融券匯入來源與更新時間；無資料股票不應過度依賴籌碼分數。',
                    'payload' => compact('latestChipDate', 'chipCount', 'activeStocks'),
                ]);
            }

            if (! $latestGlobalDate || CarbonImmutable::parse($latestGlobalDate)->lt(CarbonImmutable::now('Asia/Taipei')->subDays(3))) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'data_staleness',
                    'page' => 'global',
                    'title' => '全球市場資料過期',
                    'description' => '全球市場最新日期超過三天未更新，全球雷達與盤前報告可能失去參考性。',
                    'evidence' => "latest_global_date={$latestGlobalDate}",
                    'recommendation' => '檢查 Yahoo Finance 匯入、DNS、外部連線與 global refresh 排程。',
                    'payload' => compact('latestGlobalDate'),
                ]);
            }

            foreach ([
                'global_ai_reports' => ['page' => 'global', 'title' => '今日全球盤前 AI 報告尚未產生'],
                'theme_ai_reports' => ['page' => 'themes', 'title' => '今日題材盤前 AI 報告尚未產生'],
            ] as $table => $meta) {
                $exists = DB::table($table)->where('report_date', $this->inspectionDate)->exists();

                if (! $exists) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'ai_report_missing',
                        'page' => $meta['page'],
                        'title' => $meta['title'],
                        'description' => '盤前 AI 報告沒有今日快取，可能是 Gemini 忙碌、額度限制或排程未補跑。',
                        'evidence' => "table={$table}, report_date={$this->inspectionDate}",
                        'recommendation' => '檢查 ai_logs 最新錯誤，必要時手動補跑對應 AI report command。',
                        'payload' => ['table' => $table, 'report_date' => $this->inspectionDate],
                    ]);
                }
            }

            $this->finishRun($run, 'success', $findings, "資料品質員完成巡檢，發現 {$findings} 件問題。", [
                'active_stocks' => $activeStocks,
                'latest_price_date' => $latestPriceDate,
                'latest_score_date' => $latestScoreDate,
                'latest_technical_date' => $latestTechnicalDate,
                'latest_chip_date' => $latestChipDate,
                'latest_global_date' => $latestGlobalDate,
            ]);
        } catch (\Throwable $e) {
            $this->failRun($run, $e);
        }
    }

    private function runHomeRadarAgent(): void
    {
        $role = $this->role('home-radar');
        $run = $this->startRun($role);
        $findings = 0;

        try {
            $cardDate = DB::table('stock_radar_cards')->max('card_date');
            $cards = $this->latestRadarCards($cardDate);

            if (! $cardDate || $cards->isEmpty()) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'home_cards_missing',
                    'page' => 'home',
                    'title' => '首頁五張股票卡沒有資料',
                    'description' => 'stock_radar_cards 沒有可用資料，首頁分類無法呈現。',
                    'evidence' => "card_date={$cardDate}, count=".$cards->count(),
                    'recommendation' => '補跑 market:build-stock-radar-cards，並確認 stock_scores 與 technical indicators 已更新。',
                    'payload' => compact('cardDate'),
                ]);
            }

            $counts = $cards->groupBy('card_type')->map->count();
            foreach (['priority', 'risk', 'potential', 'low_volume', 'weak'] as $type) {
                if ((int) ($counts[$type] ?? 0) === 0) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'home_card_empty',
                        'page' => 'home',
                        'title' => $this->cardTypeName($type).'沒有候選股',
                        'description' => '該分類沒有任何股票，可能是分類規則過嚴，或上游評分資料不足。',
                        'evidence' => "card_date={$cardDate}, card_type={$type}",
                        'recommendation' => '檢查該分類條件與最新 stock_scores/technical indicators 的覆蓋率。',
                        'payload' => ['card_date' => $cardDate, 'card_type' => $type],
                    ]);
                }
            }

            $findings += $this->inspectRadarPerformance($role, $run);

            foreach ($cards as $card) {
                $metrics = $this->json($card->metrics_payload);
                $reasons = $this->json($card->reasons);
                $reasonLabels = collect($reasons)->pluck('label')->filter()->values()->all();

                if (count($reasonLabels) < 2) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'low',
                        'finding_type' => 'reason_too_few',
                        'page' => 'home',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'分類理由不足',
                        'description' => '首頁分類卡至少應有兩個明確理由，否則使用者看不出被放入該分類的依據。',
                        'evidence' => 'reasons='.implode('、', $reasonLabels),
                        'recommendation' => '調整分類規則或理由產生邏輯，避免只有單一訊號就入榜。',
                        'payload' => ['card' => $this->cardPayload($card, $metrics, $reasonLabels)],
                    ]);
                }

                if ($card->card_type === 'risk' && (int) $card->confidence_score >= 78) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'confidence_mismatch',
                        'page' => 'home',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'風險升高但信心指數偏高',
                        'description' => '風險升高股仍以看多信心指數呈現，但過高容易讓使用者誤解為高度可追。',
                        'evidence' => "card_type=risk, confidence={$card->confidence_score}, reasons=".implode('、', $reasonLabels),
                        'recommendation' => '複查信心引擎是否充分扣除過熱、法人賣超、營收轉弱或高檔放量轉弱。',
                        'payload' => ['card' => $this->cardPayload($card, $metrics, $reasonLabels)],
                    ]);
                }

                if ($card->card_type === 'weak' && (int) $card->confidence_score >= 62) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'confidence_mismatch',
                        'page' => 'home',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'持續弱勢但信心指數偏高',
                        'description' => '持續弱勢股的看多信心應偏低，若偏高代表分類或信心分數至少有一邊不一致。',
                        'evidence' => "card_type=weak, confidence={$card->confidence_score}, reasons=".implode('、', $reasonLabels),
                        'recommendation' => '檢查 weak 分類條件是否誤放轉強股票，或 confidence_payload 是否未扣除空方理由。',
                        'payload' => ['card' => $this->cardPayload($card, $metrics, $reasonLabels)],
                    ]);
                }

                if ($card->card_type === 'low_volume' && ! $this->looksLikeLowBaseVolumeBreakout($metrics, $reasonLabels)) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'high',
                        'finding_type' => 'classification_mismatch',
                        'page' => 'home',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'低檔爆量條件不足',
                        'description' => '低檔爆量應同時看到低檔或修正背景、量能明顯放大與股價轉強；目前卡片證據不完整。',
                        'evidence' => $this->metricEvidence($metrics, $reasonLabels),
                        'recommendation' => '調整低檔爆量規則：股價位置、短中期跌幅、量能倍數與收盤轉強需共同檢查。',
                        'payload' => ['card' => $this->cardPayload($card, $metrics, $reasonLabels)],
                    ]);
                }

                if ($card->card_type === 'priority' && (int) ($metrics['theme_score'] ?? 0) === 0) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'classification_mismatch',
                        'page' => 'home',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'優先觀察但題材分數為 0',
                        'description' => '優先觀察股理應具備題材、技術、籌碼或財務的多重支撐；題材分數為 0 時需要更強的其他證據。',
                        'evidence' => $this->metricEvidence($metrics, $reasonLabels),
                        'recommendation' => '檢查此股是否缺少題材映射，或優先觀察規則是否允許無題材股票入榜。',
                        'payload' => ['card' => $this->cardPayload($card, $metrics, $reasonLabels)],
                    ]);
                }
            }

            $this->finishRun($run, 'success', $findings, "首頁分類員完成巡檢，發現 {$findings} 件問題。", [
                'card_date' => $cardDate,
                'counts' => $counts->all(),
            ]);
        } catch (\Throwable $e) {
            $this->failRun($run, $e);
        }
    }

    private function runStockConsistencyAgent(): void
    {
        $role = $this->role('stock-consistency');
        $run = $this->startRun($role);
        $findings = 0;

        try {
            $cardDate = DB::table('stock_radar_cards')->max('card_date');
            $cards = $this->latestRadarCards($cardDate);

            foreach ($cards as $card) {
                $score = DB::table('stock_scores')
                    ->where('stock_id', $card->stock_id)
                    ->orderByDesc('score_date')
                    ->first(['score_date', 'confidence_score', 'confidence_payload', 'risk_flags', 'decision']);

                if (! $score) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'high',
                        'finding_type' => 'stock_score_missing',
                        'page' => 'stock',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'缺少個股評分',
                        'description' => '首頁卡片已有此股票，但個股頁找不到最新 stock_scores，兩者資料來源不一致。',
                        'evidence' => "card_date={$cardDate}, card_type={$card->card_type}",
                        'recommendation' => '補跑 decision score 或檢查 stock_id 對應。',
                        'payload' => ['card_type' => $card->card_type, 'card_date' => $cardDate],
                    ]);
                    continue;
                }

                $payload = $this->json($score->confidence_payload);
                $bull = count((array) data_get($payload, 'reasons.bull', []));
                $bear = count((array) data_get($payload, 'reasons.bear', []));
                $risk = count((array) data_get($payload, 'reasons.risk', []));
                $opportunity = (int) data_get($payload, 'opportunity_confidence', $score->confidence_score ?? 0);
                $riskConfidence = (int) data_get($payload, 'risk_confidence', 0);
                $weakConfidence = (int) data_get($payload, 'weak_confidence', 0);

                if (in_array($card->card_type, ['risk', 'weak'], true) && $bull >= ($bear + $risk + 3)) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'classification_mismatch',
                        'page' => 'stock',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'分類偏空但個股理由偏多',
                        'description' => '首頁將股票放在風險或弱勢分類，但 confidence_payload 的多方理由明顯多於空方與風險理由。',
                        'evidence' => "card_type={$card->card_type}, bull={$bull}, bear={$bear}, risk={$risk}, opportunity={$opportunity}",
                        'recommendation' => '複查首頁分類條件與個股評價文案，避免同一檔股票在不同頁面呈現相反方向。',
                        'payload' => compact('bull', 'bear', 'risk', 'opportunity', 'riskConfidence', 'weakConfidence'),
                    ]);
                }

                if (in_array($card->card_type, ['priority', 'potential'], true) && ($bear + $risk) >= ($bull + 3)) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'medium',
                        'finding_type' => 'classification_mismatch',
                        'page' => 'stock',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'分類偏多但個股風險理由偏多',
                        'description' => '首頁偏觀察分類與個股 confidence_payload 不一致，可能造成使用者誤解。',
                        'evidence' => "card_type={$card->card_type}, bull={$bull}, bear={$bear}, risk={$risk}, opportunity={$opportunity}",
                        'recommendation' => '檢查此股是否應移出優先/潛力分類，或修正個股評價的風險權重。',
                        'payload' => compact('bull', 'bear', 'risk', 'opportunity', 'riskConfidence', 'weakConfidence'),
                    ]);
                }

                if ($card->card_type === 'risk' && $opportunity >= 80 && $riskConfidence < 25) {
                    $findings += $this->finding($role, $run, [
                        'severity' => 'high',
                        'finding_type' => 'confidence_mismatch',
                        'page' => 'stock',
                        'symbol' => $card->symbol,
                        'title' => $card->name.'風險分類與風險信心不一致',
                        'description' => '股票被列入風險升高，但個股 payload 的 risk_confidence 偏低且看多信心偏高。',
                        'evidence' => "opportunity={$opportunity}, risk_confidence={$riskConfidence}, card_confidence={$card->confidence_score}",
                        'recommendation' => '修正風險分類來源，或讓 confidence engine 更明確反映風險理由。',
                        'payload' => compact('opportunity', 'riskConfidence', 'weakConfidence', 'bull', 'bear', 'risk'),
                    ]);
                }
            }

            $this->finishRun($run, 'success', $findings, "個股一致性員完成巡檢，發現 {$findings} 件問題。", [
                'card_date' => $cardDate,
                'checked_cards' => $cards->count(),
            ]);
        } catch (\Throwable $e) {
            $this->failRun($run, $e);
        }
    }

    private function runThemeRadarAgent(): void
    {
        $role = $this->role('theme-radar');
        $run = $this->startRun($role);
        $findings = 0;

        try {
            $themes = collect($this->marketContext?->theme_snapshot ?? []);
            $freshness = $this->marketContext?->freshness ?? [];
            $themeFreshness = $freshness['theme_scores'] ?? [];
            $aiReport = data_get($this->marketContext?->ai_reports ?? [], 'theme');

            if (! $this->marketContext) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'market_context_missing',
                    'page' => 'themes',
                    'title' => '題材雷達缺少每日市場背景包',
                    'description' => '題材雷達員無法讀取 Market Daily Context，因此不能判斷題材熱度、代表股與 AI 盤前觀察是否一致。',
                    'evidence' => "inspection_date={$this->inspectionDate}",
                    'recommendation' => '先確認 market:build-daily-context 是否已成功執行，再重新跑題材雷達員。',
                    'payload' => ['inspection_date' => $this->inspectionDate],
                ]);
            }

            if ($themes->count() < 10) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'theme_context_too_thin',
                    'page' => 'themes',
                    'title' => '題材背景資料不足',
                    'description' => 'Market Daily Context 內的題材快照少於 10 個，題材雷達可能只看見少數固定題材，無法完整反映市場輪動。',
                    'evidence' => 'theme_count='.$themes->count().', latest='.($themeFreshness['latest'] ?? 'null'),
                    'recommendation' => '檢查 theme_scores、題材關鍵字庫與動態題材偵測流程，確認 0 分題材沒有被帶入首頁，但題材雷達仍能保留完整題材庫。',
                    'payload' => [
                        'theme_count' => $themes->count(),
                        'theme_freshness' => $themeFreshness,
                    ],
                ]);
            }

            $zeroOrColdTopThemes = $themes
                ->take(10)
                ->filter(fn (array $theme) => (int) ($theme['heat_score'] ?? 0) <= 0)
                ->values();

            if ($zeroOrColdTopThemes->isNotEmpty()) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'theme_ranking_invalid',
                    'page' => 'themes',
                    'title' => '題材熱度排行含 0 分題材',
                    'description' => '題材熱度前段不應該出現 0 分題材。若出現，使用者會誤以為冷門題材也是市場焦點。',
                    'evidence' => 'themes='.$zeroOrColdTopThemes->pluck('name')->implode('、'),
                    'recommendation' => '首頁題材只顯示 heat_score > 0 的前 10 名；題材雷達可列全題材，但必須明確區分觀察中與真正升溫。',
                    'payload' => ['themes' => $zeroOrColdTopThemes->all()],
                ]);
            }

            if (! $aiReport) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'ai_report_missing',
                    'page' => 'themes',
                    'title' => '今日題材盤前觀察尚未產生',
                    'description' => '題材雷達缺少今天或最新的 AI 盤前觀察，使用者只能看到機械分數，較難理解題材資金輪動。',
                    'evidence' => 'theme_ai_report=null',
                    'recommendation' => '檢查 market:ai-generate-theme-premarket --live 是否成功，若 Gemini 忙碌需靠 08:40 備援排程補齊。',
                    'payload' => ['ai_report' => null],
                ]);
            } elseif (false && ($themeFreshness['updated_at'] ?? null) && ($aiReport['updated_at'] ?? null)
                && CarbonImmutable::parse($aiReport['updated_at'])->lt(CarbonImmutable::parse($themeFreshness['updated_at']))) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'low',
                    'finding_type' => 'ai_report_stale',
                    'page' => 'themes',
                    'title' => '題材 AI 盤前觀察早於題材資料更新',
                    'description' => '題材分數更新時間晚於 AI 報告，代表 AI 報告可能不是根據最新題材熱度生成。',
                    'evidence' => 'theme_updated_at='.$themeFreshness['updated_at'].', ai_updated_at='.$aiReport['updated_at'],
                    'recommendation' => '若題材資料在 08:10 後補更新，應重新產生 theme premarket report 或至少標示報告時間。',
                    'payload' => [
                        'theme_freshness' => $themeFreshness,
                        'ai_report' => $aiReport,
                    ],
                ]);
            }

            $this->finishRun($run, 'success', $findings, "題材雷達員完成巡檢，發現 {$findings} 件問題。", [
                'theme_count' => $themes->count(),
                'top_themes' => $themes->take(5)->pluck('name')->values()->all(),
                'theme_freshness' => $themeFreshness,
            ]);
        } catch (\Throwable $e) {
            $this->failRun($run, $e);
        }
    }

    private function runGlobalRadarAgent(): void
    {
        $role = $this->role('global-radar');
        $run = $this->startRun($role);
        $findings = 0;

        try {
            $markets = collect($this->marketContext?->global_markets ?? []);
            $freshness = $this->marketContext?->freshness ?? [];
            $globalFreshness = $freshness['global_market_data'] ?? [];
            $eventFreshness = $freshness['global_event_clusters'] ?? [];
            $aiReport = data_get($this->marketContext?->ai_reports ?? [], 'global');
            $events = collect(data_get($this->marketContext?->event_snapshot ?? [], 'clusters', []));

            if (! $this->marketContext) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'market_context_missing',
                    'page' => 'global',
                    'title' => '全球雷達缺少每日市場背景包',
                    'description' => '全球雷達員無法讀取 Market Daily Context，因此不能檢查國際指數、ADR、商品、匯率與 AI 盤前觀察是否完整。',
                    'evidence' => "inspection_date={$this->inspectionDate}",
                    'recommendation' => '先確認 market:build-daily-context 是否已成功執行，再重新跑全球雷達員。',
                    'payload' => ['inspection_date' => $this->inspectionDate],
                ]);
            }

            $required = ['Dow Jones', 'S&P 500', 'NASDAQ', 'SOX', 'VIX', 'TSM ADR', 'UMC ADR', 'Nikkei 225', 'Hang Seng', 'KOSPI', 'DXY', 'US10Y', 'Crude Oil', 'Gold'];
            $available = $markets->pluck('indicator')->filter()->values();
            $missing = collect($required)->diff($available)->values();

            if ($missing->isNotEmpty()) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'global_markets_missing',
                    'page' => 'global',
                    'title' => '全球雷達指標缺漏',
                    'description' => '全球雷達需要同時包含美股、亞洲市場、台積/聯電 ADR、匯率、利率與商品。缺漏指標會讓盤前觀察失去完整性。',
                    'evidence' => 'missing='.$missing->implode('、'),
                    'recommendation' => '檢查 global market refresh 的 Yahoo/資料來源抓取狀態，並確認 market_daily_contexts 有重新建立。',
                    'payload' => [
                        'missing' => $missing->all(),
                        'available' => $available->all(),
                    ],
                ]);
            }

            if (($globalFreshness['latest'] ?? null) && CarbonImmutable::parse($globalFreshness['latest'])->lt(CarbonImmutable::now('Asia/Taipei')->subDays(3))) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'global_markets_stale',
                    'page' => 'global',
                    'title' => '全球市場資料過舊',
                    'description' => '全球市場資料超過 3 天沒有有效更新，全球雷達與盤前 AI 分析可能引用舊行情。',
                    'evidence' => 'latest='.$globalFreshness['latest'].', updated_at='.($globalFreshness['updated_at'] ?? 'null'),
                    'recommendation' => '檢查 market:global-market-refresh 排程與外部資料來源，必要時手動補跑。',
                    'payload' => ['global_freshness' => $globalFreshness],
                ]);
            }

            if ($events->count() < 3) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'global_events_too_thin',
                    'page' => 'global',
                    'title' => '全球事件聚合資料不足',
                    'description' => '全球雷達盤前觀察需要重大事件支撐。事件聚合少於 3 筆時，AI 分析容易只依靠行情數字而缺少人事時地物。',
                    'evidence' => 'event_cluster_count='.$events->count().', latest='.($eventFreshness['latest'] ?? 'null'),
                    'recommendation' => '確認新聞抓取與 event clustering 是否正常，重大事件應包含標題、摘要、重要度、地區與相關題材。',
                    'payload' => [
                        'event_count' => $events->count(),
                        'event_freshness' => $eventFreshness,
                    ],
                ]);
            }

            if (! $aiReport) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'ai_report_missing',
                    'page' => 'global',
                    'title' => '今日全球盤前觀察尚未產生',
                    'description' => '全球雷達缺少最新 AI 盤前觀察，使用者無法從全球股市、匯率利率、商品與重大事件理解對台股的可能影響。',
                    'evidence' => 'global_ai_report=null',
                    'recommendation' => '檢查 market:ai-generate-global-premarket --live 是否成功，若 Gemini 忙碌需靠 08:30 備援排程補齊。',
                    'payload' => ['ai_report' => null],
                ]);
            } elseif (false && ($globalFreshness['updated_at'] ?? null) && ($aiReport['updated_at'] ?? null)
                && CarbonImmutable::parse($aiReport['updated_at'])->lt(CarbonImmutable::parse($globalFreshness['updated_at']))) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'low',
                    'finding_type' => 'ai_report_stale',
                    'page' => 'global',
                    'title' => '全球 AI 盤前觀察早於全球資料更新',
                    'description' => '全球行情資料更新時間晚於 AI 報告，代表盤前觀察可能沒有吃到最新 ADR、亞洲市場或商品資料。',
                    'evidence' => 'global_updated_at='.$globalFreshness['updated_at'].', ai_updated_at='.$aiReport['updated_at'],
                    'recommendation' => '若全球資料在 AI 報告後補齊，應重新產生 global premarket report 或至少標示報告時間。',
                    'payload' => [
                        'global_freshness' => $globalFreshness,
                        'ai_report' => $aiReport,
                    ],
                ]);
            }

            $this->finishRun($run, 'success', $findings, "全球雷達員完成巡檢，發現 {$findings} 件問題。", [
                'market_count' => $markets->count(),
                'event_count' => $events->count(),
                'global_freshness' => $globalFreshness,
            ]);
        } catch (\Throwable $e) {
            $this->failRun($run, $e);
        }
    }

    private function ensureBaseMemories(): void
    {
        $learningRole = AgentRole::query()->where('slug', 'learning-recorder')->first();
        $now = now();

        foreach ([
            [
                'title' => '低檔爆量必須同時看股價位置、量能與收盤轉強',
                'rule_summary' => '低檔爆量不能只看成交量放大，應確認長短週期位階偏低或前段修正、當日量能明顯放大，且股價收盤轉強。',
                'correct_pattern' => '股價低於或剛站回中長均線、20/60日報酬偏低或長期盤整，成交量約前日 1.8 倍以上或高於20日均量 1.5 倍以上，且收盤上漲。',
                'wrong_pattern' => '高檔爆量、收黑、只有量增但沒有股價轉強，或已經過熱卻被放入低檔爆量。',
            ],
            [
                'title' => '高檔放量轉弱要看上影線與量能倍數',
                'rule_summary' => '風險升高中的高檔放量轉弱，通常需要股價已漲多或乖離偏大，當日開高後壓回形成明顯上影線，成交量約前一日 2 倍以上。',
                'correct_pattern' => '20日漲幅偏高或乖離過大，收盤低於開盤，上影線明顯，量能約前日兩倍以上。',
                'wrong_pattern' => '正常價漲量增、低檔轉強或沒有放量上影線，不能硬放入風險升高。',
            ],
            [
                'title' => '風險與弱勢分類不能只看單一訊號',
                'rule_summary' => '風險升高與持續弱勢應同時檢查技術、籌碼、財務與題材背景，避免單一 MACD 或均線訊號造成錯放。',
                'correct_pattern' => '至少兩個以上負面理由，且理由與股價位置、量能或法人/財務資料一致。',
                'wrong_pattern' => '看多理由明顯多於空方理由，或只有單一警示理由卻列入風險/弱勢。',
            ],
        ] as $memory) {
            AgentMemory::query()->updateOrCreate(
                [
                    'memory_type' => 'rule',
                    'title' => $memory['title'],
                ],
                [
                    'agent_role_id' => $learningRole?->id,
                    'status' => 'active',
                    'rule_summary' => $memory['rule_summary'],
                    'correct_pattern' => $memory['correct_pattern'],
                    'wrong_pattern' => $memory['wrong_pattern'],
                    'codex_feedback' => '董事長已確認此規則，代理人巡檢時需優先參考。',
                    'confidence' => 90,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    private function inspectRadarPerformance(AgentRole $role, AgentRun $run): int
    {
        if (! $this->hasTable('stock_radar_observations') || ! $this->hasTable('stock_radar_observation_checks')) {
            return 0;
        }

        $findings = 0;
        $performanceRows = DB::table('stock_radar_observation_checks as c')
            ->join('stock_radar_observations as o', 'o.id', '=', 'c.stock_radar_observation_id')
            ->where('c.days_since_selected', 1)
            ->where('o.selected_date', '>=', CarbonImmutable::parse($this->inspectionDate)->subDays(45)->toDateString())
            ->groupBy('o.card_type')
            ->get([
                'o.card_type',
                DB::raw('count(*) as total'),
                DB::raw('count(c.change_pct) as valid_count'),
                DB::raw('round(avg(c.change_pct), 2) as avg_change_pct'),
                DB::raw('sum(case when c.change_pct > 0 then 1 else 0 end) as up_count'),
                DB::raw('sum(case when c.change_pct < 0 then 1 else 0 end) as down_count'),
            ]);

        foreach ($performanceRows as $row) {
            $valid = (int) $row->valid_count;
            if ($valid < 6) {
                continue;
            }

            $avg = (float) $row->avg_change_pct;
            $upRate = $valid > 0 ? round(((int) $row->up_count / $valid) * 100, 1) : 0.0;
            $typeName = $this->cardTypeName((string) $row->card_type);

            if (in_array($row->card_type, ['priority', 'potential'], true) && ($avg < -0.5 || $upRate < 45)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'high',
                    'finding_type' => 'radar_rule_underperforming',
                    'page' => 'home',
                    'title' => $typeName.'隔日表現不如預期',
                    'description' => $typeName.'本來應該偏向看多觀察，但最近樣本的隔日平均漲跌與上漲率偏弱，代表篩選規則可能需要加入追高、乖離、量價鈍化或大盤風險濾網。',
                    'evidence' => "card_type={$row->card_type}, valid={$valid}, avg={$avg}%, up_rate={$upRate}%",
                    'recommendation' => '檢查該卡片近期入選原因，若常在前一日大漲後入選，應加入前一日漲幅過大排除或高檔爆量轉弱條件。',
                    'payload' => [
                        'card_type' => $row->card_type,
                        'valid_count' => $valid,
                        'avg_change_pct' => $avg,
                        'up_rate' => $upRate,
                    ],
                ]);
            }

            if ($row->card_type === 'risk' && ($avg > 0.5 || $upRate > 55)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'radar_rule_underperforming',
                    'page' => 'home',
                    'title' => '風險升高隔日沒有明顯轉弱',
                    'description' => '風險升高卡應該能抓到隔日容易拉回或震盪的股票。若平均仍偏漲，代表風險條件可能太寬，或把強勢續漲股誤判成過熱。',
                    'evidence' => "valid={$valid}, avg={$avg}%, up_rate={$upRate}%",
                    'recommendation' => '提高風險卡對高檔長上影線、爆量不漲、法人轉賣、融資急增等條件的要求，避免單純強勢股被列入風險。',
                    'payload' => [
                        'card_type' => $row->card_type,
                        'valid_count' => $valid,
                        'avg_change_pct' => $avg,
                        'up_rate' => $upRate,
                    ],
                ]);
            }

            if ($row->card_type === 'weak' && ($avg > 0.8 || $upRate > 55)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'radar_rule_underperforming',
                    'page' => 'home',
                    'title' => '持續弱勢隔日反彈比例偏高',
                    'description' => '持續弱勢卡如果隔日反彈比例偏高，可能混入跌深反彈或低檔轉強股票，分類條件需要更精準。',
                    'evidence' => "valid={$valid}, avg={$avg}%, up_rate={$upRate}%",
                    'recommendation' => '檢查弱勢股是否仍有量價轉強、KD/MACD 修復或低檔爆量訊號，若有應移到低檔爆量或潛力觀察。',
                    'payload' => [
                        'card_type' => $row->card_type,
                        'valid_count' => $valid,
                        'avg_change_pct' => $avg,
                        'up_rate' => $upRate,
                    ],
                ]);
            }
        }

        $findings += $this->inspectReasonPerformance($role, $run);

        return $findings;
    }

    private function inspectReasonPerformance(AgentRole $role, AgentRun $run): int
    {
        $rows = DB::table('stock_radar_observations as o')
            ->leftJoin('stock_radar_observation_checks as c', function ($join) {
                $join->on('c.stock_radar_observation_id', '=', 'o.id')
                    ->where('c.days_since_selected', 1);
            })
            ->where('o.selected_date', '>=', CarbonImmutable::parse($this->inspectionDate)->subDays(45)->toDateString())
            ->get(['o.card_type', 'o.entry_reasons', 'c.change_pct']);

        $stats = [];
        foreach ($rows as $row) {
            $labels = collect($this->json($row->entry_reasons))
                ->map(fn ($reason) => is_array($reason) ? ($reason['label'] ?? null) : null)
                ->filter()
                ->unique();

            foreach ($labels as $label) {
                $key = $row->card_type.'|'.$label;
                $stats[$key] ??= [
                    'card_type' => $row->card_type,
                    'label' => $label,
                    'valid' => 0,
                    'up' => 0,
                    'sum' => 0.0,
                ];

                if ($row->change_pct !== null) {
                    $change = (float) $row->change_pct;
                    $stats[$key]['valid']++;
                    $stats[$key]['sum'] += $change;
                    if ($change > 0) {
                        $stats[$key]['up']++;
                    }
                }
            }
        }

        $findings = 0;
        foreach ($stats as $stat) {
            if ($stat['valid'] < 8) {
                continue;
            }

            $avg = round($stat['sum'] / $stat['valid'], 2);
            $winRate = round(($stat['up'] / $stat['valid']) * 100, 1);
            $typeName = $this->cardTypeName((string) $stat['card_type']);

            if (in_array($stat['card_type'], ['priority', 'potential', 'low_volume'], true) && ($avg < -0.6 || $winRate < 42)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'reason_signal_underperforming',
                    'page' => 'home',
                    'title' => $typeName.'原因「'.$stat['label'].'」近期勝率偏低',
                    'description' => '這個原因標籤在看多或觀察卡片中近期隔日表現偏弱，代表它可能不能單獨作為加分理由，或需要搭配量能、乖離與籌碼條件。',
                    'evidence' => "label={$stat['label']}, valid={$stat['valid']}, avg={$avg}%, win_rate={$winRate}%",
                    'recommendation' => '降低此原因在看多卡片中的權重，或要求同時出現其他確認訊號才納入排序。',
                    'payload' => [
                        'card_type' => $stat['card_type'],
                        'label' => $stat['label'],
                        'valid_count' => $stat['valid'],
                        'avg_change_pct' => $avg,
                        'win_rate' => $winRate,
                    ],
                ]);
            }

            if ($stat['card_type'] === 'risk' && ($avg > 0.6 || $winRate > 58)) {
                $findings += $this->finding($role, $run, [
                    'severity' => 'medium',
                    'finding_type' => 'reason_signal_underperforming',
                    'page' => 'home',
                    'title' => '風險原因「'.$stat['label'].'」近期沒有帶來回檔',
                    'description' => '這個風險原因近期隔日仍偏漲，代表它可能只是強勢延續中的正常現象，不應單獨作為風險升高依據。',
                    'evidence' => "label={$stat['label']}, valid={$stat['valid']}, avg={$avg}%, win_rate={$winRate}%",
                    'recommendation' => '調低此風險原因權重，或要求搭配高檔爆量、長上影線、籌碼轉賣等條件才判定風險升高。',
                    'payload' => [
                        'card_type' => $stat['card_type'],
                        'label' => $stat['label'],
                        'valid_count' => $stat['valid'],
                        'avg_change_pct' => $avg,
                        'win_rate' => $winRate,
                    ],
                ]);
            }
        }

        return $findings;
    }

    private function latestRadarCards(?string $cardDate): Collection
    {
        if (! $cardDate) {
            return collect();
        }

        return DB::table('stock_radar_cards')
            ->join('stocks', 'stocks.id', '=', 'stock_radar_cards.stock_id')
            ->where('stock_radar_cards.card_date', $cardDate)
            ->orderBy('stock_radar_cards.card_type')
            ->orderBy('stock_radar_cards.rank')
            ->get([
                'stock_radar_cards.*',
                'stocks.symbol',
                'stocks.name',
            ]);
    }

    private function role(string $slug): AgentRole
    {
        return AgentRole::query()->where('slug', $slug)->firstOrFail();
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadMarketContext(): ?MarketDailyContext
    {
        return MarketDailyContext::query()
            ->where('context_date', '<=', $this->inspectionDate)
            ->orderByDesc('context_date')
            ->orderByRaw("case session when 'night' then 4 when 'aftermarket' then 3 when 'premarket' then 2 when 'daily' then 1 else 0 end desc")
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function marketContextReference(): array
    {
        if (! $this->marketContext) {
            return [
                'available' => false,
                'inspection_date' => $this->inspectionDate,
            ];
        }

        return [
            'available' => true,
            'id' => $this->marketContext->id,
            'context_date' => optional($this->marketContext->context_date)->toDateString(),
            'session' => $this->marketContext->session,
            'market_phase' => $this->marketContext->market_phase,
            'risk_score' => $this->marketContext->risk_score,
            'opportunity_score' => $this->marketContext->opportunity_score,
            'summary' => $this->marketContext->summary,
            'updated_at' => optional($this->marketContext->updated_at)->toDateTimeString(),
        ];
    }

    private function startRun(AgentRole $role): AgentRun
    {
        return AgentRun::query()->create([
            'agent_role_id' => $role->id,
            'run_key' => $role->slug.':'.$this->inspectionDate.':'.now('Asia/Taipei')->format('His'),
            'status' => 'running',
            'started_at' => now(),
            'input_context' => [
                'inspection_date' => $this->inspectionDate,
                'agent_slug' => $role->slug,
                'market_context' => $this->marketContextReference(),
            ],
        ]);
    }

    private function finishRun(AgentRun $run, string $status, int $findings, string $summary, array $output = []): void
    {
        $started = $run->started_at ? CarbonImmutable::parse($run->started_at) : CarbonImmutable::now();

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round($started->diffInMilliseconds(now())),
            'findings_count' => $findings,
            'memories_count' => 0,
            'summary' => $summary,
            'output_context' => $output,
        ]);

        $this->line($summary);
    }

    private function failRun(AgentRun $run, \Throwable $e): void
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_ms' => $run->started_at
                ? (int) round(CarbonImmutable::parse($run->started_at)->diffInMilliseconds(now()))
                : null,
            'error_message' => $e->getMessage(),
        ]);

        $this->error($run->role?->name.' failed: '.$e->getMessage());
    }

    /**
     * @param array<string,mixed> $data
     */
    private function finding(AgentRole $role, AgentRun $run, array $data): int
    {
        $lookup = [
            'agent_role_id' => $role->id,
            'status' => 'pending',
            'finding_type' => $data['finding_type'],
            'page' => $data['page'] ?? null,
            'symbol' => $data['symbol'] ?? null,
            'title' => $data['title'],
        ];

        $existing = AgentFinding::query()
            ->where($lookup)
            ->whereBetween('created_at', [
                CarbonImmutable::parse($this->inspectionDate, 'Asia/Taipei')->startOfDay()->utc(),
                CarbonImmutable::parse($this->inspectionDate, 'Asia/Taipei')->endOfDay()->utc(),
            ])
            ->first();

        $payload = $data['payload'] ?? [];
        $payload = is_array($payload) ? $payload : ['raw' => $payload];
        $payload['market_context'] = $this->marketContextReference();
        $payload['related_memories'] = $this->relatedMemories($role, $data);

        $values = [
            'agent_run_id' => $run->id,
            'severity' => $data['severity'] ?? 'info',
            'theme_slug' => $data['theme_slug'] ?? null,
            'description' => $data['description'],
            'evidence' => $data['evidence'] ?? null,
            'recommendation' => $data['recommendation'] ?? null,
            'payload' => $payload,
            'updated_at' => now(),
        ];

        if ($existing) {
            $existing->update($values);

            return 0;
        }

        AgentFinding::query()->create(array_merge($lookup, $values, [
            'created_at' => now(),
        ]));

        return 1;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function relatedMemories(AgentRole $role, array $data): array
    {
        $text = implode(' ', array_filter([
            $role->slug,
            $data['finding_type'] ?? '',
            $data['page'] ?? '',
            $data['title'] ?? '',
            $data['description'] ?? '',
            $data['evidence'] ?? '',
            $data['recommendation'] ?? '',
        ]));

        $memories = AgentMemory::query()
            ->leftJoin('agent_roles', 'agent_roles.id', '=', 'agent_memories.agent_role_id')
            ->where('agent_memories.status', 'active')
            ->where(function ($query) use ($role) {
                $query->whereNull('agent_memories.agent_role_id')
                    ->orWhere('agent_roles.slug', $role->slug)
                    ->orWhere('agent_roles.slug', 'learning-recorder');
            })
            ->orderByDesc('agent_memories.confidence')
            ->get([
                'agent_memories.id',
                'agent_memories.title',
                'agent_memories.memory_type',
                'agent_memories.rule_summary',
                'agent_memories.correct_pattern',
                'agent_memories.wrong_pattern',
                'agent_memories.confidence',
                'agent_memories.payload',
                'agent_roles.name as role_name',
                'agent_roles.slug as role_slug',
            ]);

        return $memories
            ->map(function ($memory) use ($text) {
                $payload = $this->json($memory->payload);
                $terms = array_values(array_unique(array_filter(array_merge(
                    [$memory->title, $memory->memory_type, data_get($payload, 'category')],
                    (array) data_get($payload, 'signals', []),
                    $this->keywordsForMemory((string) $memory->title),
                ))));
                $score = collect($terms)->sum(function (string $term) use ($text) {
                    if ($term === '') {
                        return 0;
                    }

                    return Str::contains($text, $term) ? (mb_strlen($term) >= 4 ? 3 : 1) : 0;
                });

                if ($score === 0) {
                    return null;
                }

                return [
                    'id' => (int) $memory->id,
                    'title' => $memory->title,
                    'memory_type' => $memory->memory_type,
                    'role' => $memory->role_name,
                    'score' => $score,
                    'confidence' => (int) $memory->confidence,
                    'rule_summary' => $memory->rule_summary,
                    'correct_pattern' => $memory->correct_pattern,
                    'wrong_pattern' => $memory->wrong_pattern,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $memory) => sprintf('%03d-%03d', $memory['score'], $memory['confidence']))
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function keywordsForMemory(string $title): array
    {
        return match (true) {
            Str::contains($title, '低檔爆量') => ['低檔爆量', '低檔', '爆量', '量能放大', '股價轉強', 'classification_mismatch', 'low_volume'],
            Str::contains($title, '高檔放量') => ['高檔放量', '放量轉弱', '上影線', '風險升高', 'risk'],
            Str::contains($title, '風險與弱勢') => ['風險升高', '持續弱勢', '理由不足', 'classification_mismatch', 'confidence_mismatch', 'weak', 'risk'],
            Str::contains($title, '多方技術') => ['優先觀察', '潛力觀察', '多方', '技術', '黃金交叉', 'priority', 'potential'],
            Str::contains($title, '漲多風險') => ['風險升高', '乖離', '過熱', '量價', 'risk'],
            Str::contains($title, '法人買超') => ['法人', '籌碼', '買超', '賣超', 'institutional', 'chip'],
            Str::contains($title, '融資快速') => ['融資', '籌碼', '風險', 'margin'],
            Str::contains($title, '營收') => ['營收', '財報', 'fundamental', 'confidence_mismatch'],
            Str::contains($title, '本益比') => ['本益比', 'PER', '評價', '高估值'],
            Str::contains($title, '題材') => ['題材', 'theme', 'theme_score'],
            Str::contains($title, '全球') => ['全球', 'global', 'ADR', '匯率', '利率'],
            Str::contains($title, '資料更新') => ['資料', '更新', '過期', '覆蓋', 'data_coverage', 'data_staleness', 'ai_report_missing'],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<int,string> $reasonLabels
     */
    private function looksLikeLowBaseVolumeBreakout(array $metrics, array $reasonLabels): bool
    {
        $volumeOk = (float) ($metrics['volume_multiple'] ?? 0) >= 1.8
            || (float) ($metrics['volume_ratio20'] ?? 0) >= 1.5;
        $positionOk = in_array('低檔整理', $reasonLabels, true)
            || in_array('前段修正', $reasonLabels, true)
            || (float) ($metrics['return20'] ?? 0) <= -4
            || (float) ($metrics['return60'] ?? 0) <= -8
            || (float) ($metrics['bais20'] ?? 0) <= 3;
        $priceOk = in_array('股價轉強', $reasonLabels, true)
            || in_array('20日突破', $reasonLabels, true);
        $notOverheated = (float) ($metrics['bais20'] ?? 0) < 12
            && (float) ($metrics['return20'] ?? 0) < 18;

        return $volumeOk && $positionOk && $priceOk && $notOverheated;
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<int,string> $reasonLabels
     */
    private function metricEvidence(array $metrics, array $reasonLabels): string
    {
        return 'reasons='.implode('、', $reasonLabels)
            .', return20='.($metrics['return20'] ?? 'null')
            .', return60='.($metrics['return60'] ?? 'null')
            .', bais20='.($metrics['bais20'] ?? 'null')
            .', volume_multiple='.($metrics['volume_multiple'] ?? 'null')
            .', volume_ratio20='.($metrics['volume_ratio20'] ?? 'null')
            .', theme_score='.($metrics['theme_score'] ?? 'null');
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<int,string> $reasonLabels
     * @return array<string,mixed>
     */
    private function cardPayload(object $card, array $metrics, array $reasonLabels): array
    {
        return [
            'card_date' => (string) $card->card_date,
            'card_type' => $card->card_type,
            'rank' => (int) $card->rank,
            'confidence' => (int) $card->confidence_score,
            'reasons' => $reasonLabels,
            'metrics' => $metrics,
        ];
    }

    private function cardTypeName(string $type): string
    {
        return [
            'priority' => '今日優先觀察',
            'risk' => '今日風險升高',
            'potential' => '潛力觀察',
            'low_volume' => '低檔爆量',
            'weak' => '持續弱勢',
        ][$type] ?? $type;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
