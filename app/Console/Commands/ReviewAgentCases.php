<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentMemory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewAgentCases extends Command
{
    protected $signature = 'market:agents-review-cases {--limit=30 : Maximum pending cases to review}';

    protected $description = 'Conservatively triage pending MarketX agent cases and write Codex feedback memories.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cases = AgentFinding::query()
            ->with('role:id,name,slug')
            ->where('status', 'pending')
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $reviewed = 0;
        $keptPending = 0;

        foreach ($cases as $case) {
            $decision = $this->decide($case);

            if ($decision['status'] === 'pending') {
                $keptPending++;
                $this->line($this->caseNo($case).' keep pending: '.$decision['feedback']);
                continue;
            }

            DB::transaction(function () use ($case, $decision) {
                $case->update([
                    'status' => $decision['status'],
                    'codex_feedback' => $decision['feedback'],
                    'reviewed_at' => now(),
                ]);

                $this->writeMemory($case, $decision);
            });

            $reviewed++;
            $this->line($this->caseNo($case).' '.$decision['status'].': '.$decision['feedback']);
        }

        $this->info("Agent cases reviewed: {$reviewed}, kept pending: {$keptPending}");

        return self::SUCCESS;
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function decide(AgentFinding $case): array
    {
        return match ($case->finding_type) {
            'ai_report_missing' => $this->reviewAiReportMissing($case),
            'home_cards_missing' => $this->reviewHomeCardsMissing($case),
            'home_card_empty' => $this->reviewHomeCardEmpty($case),
            'stock_score_missing' => $this->reviewStockScoreMissing($case),
            'data_staleness', 'data_coverage' => $this->reviewDataCase($case),
            'reason_too_few' => [
                'status' => 'observing',
                'feedback' => '此案件屬於呈現品質問題，尚不直接改程式；先標記觀察，若同類案件連續出現，凌晨任務再調整理由產生規則。',
            ],
            'classification_mismatch', 'confidence_mismatch' => [
                'status' => 'pending',
                'feedback' => '此案件可能涉及分類規則或信心引擎，需要 Codex 檢查實際股票資料與頁面表現後再決定是否修正。',
            ],
            default => [
                'status' => 'observing',
                'feedback' => '目前沒有足夠自動判斷規則，先標記觀察並保留案例供後續知識庫累積。',
            ],
        };
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function reviewAiReportMissing(AgentFinding $case): array
    {
        $payload = $this->payload($case);
        $table = (string) data_get($payload, 'table');
        $date = (string) data_get($payload, 'report_date', CarbonImmutable::now('Asia/Taipei')->toDateString());

        if ($table !== '' && DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->where('report_date', $date)->exists()) {
            return [
                'status' => 'resolved',
                'feedback' => "已確認 {$table} 在 {$date} 已有報告，案件結案。後續若再發生，優先檢查 Gemini 503 或排程補跑。",
            ];
        }

        return [
            'status' => 'pending',
            'feedback' => 'AI 報告仍未補齊，需要檢查 ai_logs、Gemini 回應與排程補跑機制。',
        ];
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function reviewHomeCardsMissing(AgentFinding $case): array
    {
        $latestCardDate = DB::table('stock_radar_cards')->max('card_date');
        $count = $latestCardDate
            ? (int) DB::table('stock_radar_cards')->where('card_date', $latestCardDate)->count()
            : 0;

        if ($count > 0) {
            return [
                'status' => 'resolved',
                'feedback' => "已確認首頁股票卡在 {$latestCardDate} 有 {$count} 筆資料，案件結案。",
            ];
        }

        return [
            'status' => 'pending',
            'feedback' => '首頁股票卡仍無資料，需要補跑 market:build-stock-radar-cards 並檢查上游評分。',
        ];
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function reviewHomeCardEmpty(AgentFinding $case): array
    {
        $payload = $this->payload($case);
        $type = (string) data_get($payload, 'card_type');
        $latestCardDate = DB::table('stock_radar_cards')->max('card_date');
        $count = $latestCardDate && $type !== ''
            ? (int) DB::table('stock_radar_cards')->where('card_date', $latestCardDate)->where('card_type', $type)->count()
            : 0;

        if ($count > 0) {
            return [
                'status' => 'resolved',
                'feedback' => "已確認 {$type} 分類在 {$latestCardDate} 已有 {$count} 筆資料，案件結案。",
            ];
        }

        return [
            'status' => 'observing',
            'feedback' => "目前 {$type} 分類仍沒有候選股。先觀察，不直接放寬規則，避免為了補滿卡片而降低分類品質。",
        ];
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function reviewStockScoreMissing(AgentFinding $case): array
    {
        if (! $case->symbol) {
            return [
                'status' => 'pending',
                'feedback' => '案件缺少股票代號，需人工檢查 finding payload。',
            ];
        }

        $exists = DB::table('stocks')
            ->join('stock_scores', 'stock_scores.stock_id', '=', 'stocks.id')
            ->where('stocks.symbol', $case->symbol)
            ->exists();

        if ($exists) {
            return [
                'status' => 'resolved',
                'feedback' => "已確認 {$case->symbol} 目前已有 stock_scores，案件結案。",
            ];
        }

        return [
            'status' => 'pending',
            'feedback' => "{$case->symbol} 仍缺少 stock_scores，需要檢查 decision score pipeline。",
        ];
    }

    /**
     * @return array{status:string,feedback:string}
     */
    private function reviewDataCase(AgentFinding $case): array
    {
        $activeStocks = (int) DB::table('stocks')->where('is_active', true)->count();
        $minimumBroadCoverage = max(1, (int) floor($activeStocks * 0.88));
        $latestPriceDate = DB::table('stock_prices_1d')->max('trade_date');
        $latestTechnicalDate = DB::table('stock_technical_indicators_1d')->max('trade_date');
        $latestScoreDate = DB::table('stock_scores')->max('score_date');
        $priceCount = $latestPriceDate ? (int) DB::table('stock_prices_1d')->where('trade_date', $latestPriceDate)->count() : 0;
        $scoreCount = $latestScoreDate ? (int) DB::table('stock_scores')->where('score_date', $latestScoreDate)->count() : 0;
        $technicalCount = $latestTechnicalDate ? (int) DB::table('stock_technical_indicators_1d')->where('trade_date', $latestTechnicalDate)->count() : 0;

        $healthy = $latestPriceDate
            && $latestTechnicalDate === $latestPriceDate
            && $priceCount >= $minimumBroadCoverage
            && $scoreCount >= $minimumBroadCoverage
            && $technicalCount >= (int) floor($priceCount * 0.9);

        if ($healthy) {
            return [
                'status' => 'resolved',
                'feedback' => "已確認資料覆蓋恢復：價格 {$priceCount}、分數 {$scoreCount}、技術 {$technicalCount}，日期 {$latestPriceDate}，案件結案。",
            ];
        }

        return [
            'status' => 'pending',
            'feedback' => "資料仍需檢查：price_date={$latestPriceDate}, technical_date={$latestTechnicalDate}, price_count={$priceCount}, score_count={$scoreCount}, technical_count={$technicalCount}。",
        ];
    }

    /**
     * @param array{status:string,feedback:string} $decision
     */
    private function writeMemory(AgentFinding $case, array $decision): void
    {
        $caseNo = $this->caseNo($case);
        $memoryType = 'feedback:'.$decision['status'];
        $confidence = match ($decision['status']) {
            'resolved' => 84,
            'observing' => 76,
            'rejected' => 72,
            'accepted' => 90,
            default => 70,
        };

        AgentMemory::query()->updateOrCreate(
            [
                'memory_type' => $memoryType,
                'title' => $caseNo.' '.$case->title,
            ],
            [
                'agent_role_id' => $case->agent_role_id,
                'agent_finding_id' => $case->id,
                'status' => 'active',
                'rule_summary' => $case->description,
                'correct_pattern' => $decision['feedback'],
                'wrong_pattern' => $case->evidence,
                'codex_feedback' => $decision['feedback'],
                'confidence' => $confidence,
                'examples' => [
                    [
                        'case_no' => $caseNo,
                        'finding_id' => $case->id,
                        'symbol' => $case->symbol,
                        'page' => $case->page,
                        'finding_type' => $case->finding_type,
                        'status' => $decision['status'],
                    ],
                ],
                'payload' => [
                    'source' => 'case_review_engine',
                    'review_status' => $decision['status'],
                    'finding_payload' => $this->payload($case),
                ],
                'last_used_at' => now(),
            ],
        );
    }

    private function caseNo(AgentFinding $case): string
    {
        return 'AG-'.CarbonImmutable::parse($case->created_at)->timezone('Asia/Taipei')->format('Ymd').'-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(AgentFinding $case): array
    {
        return is_array($case->payload) ? $case->payload : [];
    }
}
