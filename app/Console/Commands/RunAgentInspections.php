<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentMemory;
use App\Models\AgentRole;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunAgentInspections extends Command
{
    protected $signature = 'market:agents-run
        {--date= : Inspection date, default today in Asia/Taipei}
        {--agent=* : Limit to agent slugs: data-quality, home-radar, stock-consistency}';

    protected $description = 'Run rule-based MarketX agent inspections and write findings to the agent communication tables.';

    private string $inspectionDate;

    public function handle(): int
    {
        $this->inspectionDate = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();

        $this->ensureBaseMemories();

        $agents = collect($this->option('agent'))
            ->filter()
            ->values();

        $available = [
            'data-quality' => fn () => $this->runDataQualityAgent(),
            'home-radar' => fn () => $this->runHomeRadarAgent(),
            'stock-consistency' => fn () => $this->runStockConsistencyAgent(),
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
            ->whereDate('created_at', $this->inspectionDate)
            ->first();

        $values = [
            'agent_run_id' => $run->id,
            'severity' => $data['severity'] ?? 'info',
            'theme_slug' => $data['theme_slug'] ?? null,
            'description' => $data['description'],
            'evidence' => $data['evidence'] ?? null,
            'recommendation' => $data['recommendation'] ?? null,
            'payload' => $data['payload'] ?? null,
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
