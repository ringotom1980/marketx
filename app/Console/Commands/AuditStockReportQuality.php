<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentRole;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditStockReportQuality extends Command
{
    protected $signature = 'market:audit-stock-report-quality
        {--limit=120 : Maximum latest stock reports to audit}
        {--date= : Report date, default latest stock report date}';

    protected $description = 'Audit generated stock reports for repeated wording, tone mismatch, and card/report contradictions.';

    private ?AgentRole $role = null;
    private ?AgentRun $run = null;
    private string $auditDate = '';

    public function handle(): int
    {
        if (! $this->hasRequiredTables()) {
            $this->error('Missing required tables for stock report quality audit.');

            return self::FAILURE;
        }

        $this->role = AgentRole::query()->where('slug', 'stock-consistency')->first();
        if (! $this->role) {
            $this->error('Missing agent role: stock-consistency');

            return self::FAILURE;
        }

        $this->auditDate = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : (string) DB::table('stock_reports')->max('report_date');

        if ($this->auditDate === '') {
            $this->warn('No stock reports to audit.');

            return self::SUCCESS;
        }

        $this->run = AgentRun::query()->create([
            'agent_role_id' => $this->role->id,
            'run_key' => 'stock-report-quality:'.$this->auditDate.':'.now('Asia/Taipei')->format('His'),
            'status' => 'running',
            'started_at' => now(),
            'input_context' => [
                'audit_date' => $this->auditDate,
                'limit' => (int) $this->option('limit'),
            ],
        ]);

        $findings = 0;

        try {
            foreach ($this->latestReports() as $report) {
                $findings += $this->auditReport($report);
            }

            $this->finishRun('success', $findings, "個股報告品質稽核完成，建立 {$findings} 筆案件。");
        } catch (\Throwable $e) {
            $this->run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return self::SUCCESS;
    }

    private function hasRequiredTables(): bool
    {
        foreach (['agent_roles', 'agent_runs', 'agent_findings', 'stock_reports', 'stocks'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function latestReports()
    {
        $limit = max(1, min(500, (int) $this->option('limit')));

        return DB::table('stock_reports')
            ->join('stocks', 'stocks.id', '=', 'stock_reports.stock_id')
            ->leftJoin('stock_radar_cards', function ($join) {
                $join->on('stock_radar_cards.stock_id', '=', 'stocks.id')
                    ->whereRaw('stock_radar_cards.card_date = (select max(src.card_date) from stock_radar_cards src)');
            })
            ->where('stock_reports.report_date', $this->auditDate)
            ->orderByDesc('stock_reports.updated_at')
            ->limit($limit)
            ->get([
                'stock_reports.id as report_id',
                'stock_reports.report_date',
                'stock_reports.model',
                'stock_reports.summary',
                'stock_reports.data_pack',
                'stocks.id as stock_id',
                'stocks.symbol',
                'stocks.name',
                'stock_radar_cards.card_type',
                'stock_radar_cards.reasons',
                'stock_radar_cards.confidence_score as card_confidence',
            ]);
    }

    private function auditReport(object $report): int
    {
        $count = 0;
        $summary = $this->normalize((string) $report->summary);

        if ($summary === '') {
            return $this->finding($report, 'high', 'stock_report_empty', '個股報告內容空白', 'stock_reports.summary 是空值，使用者無法取得分析內容。', [
                'report_id' => $report->report_id,
            ]);
        }

        $repeated = $this->repeatedSentences($summary);
        if ($repeated !== []) {
            $count += $this->finding($report, 'medium', 'stock_report_repeated_sentence', '個股報告出現重複語句', '同一篇報告出現高度重複語句，會讓內容看起來像制式拼接。', [
                'report_id' => $report->report_id,
                'repeated_sentences' => $repeated,
            ], '調整語句庫或模板選用規則，避免同一篇報告重複使用相近句型。');
        }

        $contradictions = $this->contradictions($summary);
        if ($contradictions !== []) {
            $count += $this->finding($report, 'high', 'stock_report_contradiction', '個股報告方向互相矛盾', '同一份報告同時出現偏多與偏空的互斥描述，容易讓使用者無法判讀。', [
                'report_id' => $report->report_id,
                'contradictions' => $contradictions,
            ], '檢查 StockReportPhraseComposer 的 tone 與 condition_key 選擇，避免同段落混用互斥語句。');
        }

        $cardMismatch = $this->cardToneMismatch($summary, (string) ($report->card_type ?? ''));
        if ($cardMismatch !== null) {
            $count += $this->finding($report, $cardMismatch['severity'], 'stock_report_card_tone_mismatch', '個股報告與五張卡分類語氣不一致', $cardMismatch['description'], [
                'report_id' => $report->report_id,
                'card_type' => $report->card_type,
                'card_confidence' => $report->card_confidence,
                'bull_terms' => $this->termHits($summary, $this->bullTerms()),
                'risk_terms' => $this->termHits($summary, $this->riskTerms()),
                'bear_terms' => $this->termHits($summary, $this->bearTerms()),
            ], '依五張卡分類調整文章模板或總評語氣，避免分類與報告結論互相打架。');
        }

        if ($this->looksTooGeneric($summary)) {
            $count += $this->finding($report, 'medium', 'stock_report_too_generic', '個股報告過於制式', '報告缺少足夠的價格、題材、技術、籌碼或財務具體數字，容易像通用罐頭文字。', [
                'report_id' => $report->report_id,
                'numeric_hits' => preg_match_all('/\d+(?:\.\d+)?%?/u', $summary),
            ], '增加段落模板中的數據佔位符，並確認 composer 有提供近期漲跌、量能、營收與籌碼變數。');
        }

        return $count;
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<int, string>
     */
    private function repeatedSentences(string $summary): array
    {
        $parts = preg_split('/[。！？\n]+/u', $summary) ?: [];
        $seen = [];
        $repeated = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 18) {
                continue;
            }

            $fingerprint = mb_substr(preg_replace('/[\d\.\s%％，、；：]/u', '', $part) ?? $part, 0, 42);
            if ($fingerprint === '') {
                continue;
            }

            if (isset($seen[$fingerprint])) {
                $repeated[] = $part;
            }

            $seen[$fingerprint] = true;
        }

        return array_values(array_slice(array_unique($repeated), 0, 5));
    }

    /**
     * @return array<int, string>
     */
    private function contradictions(string $summary): array
    {
        $pairs = [
            ['股價仍在主要均線下方', ['均線結構偏多', '短中期均線之上', '均線往上']],
            ['弱勢結構', ['多方結構', '買方掌握', '短線動能明顯']],
            ['動能沒有跟上', ['動能正在改善', 'MACD 柱狀體由弱轉強']],
            ['風險升高', ['條件值得追蹤', '偏向多方觀察']],
            ['跌深後修正', ['延續強勢', '資金尚未明顯退場']],
        ];

        $hits = [];
        foreach ($pairs as [$negative, $positives]) {
            if (! Str::contains($summary, $negative)) {
                continue;
            }

            foreach ($positives as $positive) {
                if (Str::contains($summary, $positive)) {
                    $hits[] = $negative.' / '.$positive;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @return array{severity:string,description:string}|null
     */
    private function cardToneMismatch(string $summary, string $cardType): ?array
    {
        if ($cardType === '') {
            return null;
        }

        $bull = count($this->termHits($summary, $this->bullTerms()));
        $risk = count($this->termHits($summary, $this->riskTerms()));
        $bear = count($this->termHits($summary, $this->bearTerms()));

        if (in_array($cardType, ['risk', 'weak'], true) && $bull >= max(3, $risk + $bear + 2)) {
            return [
                'severity' => 'high',
                'description' => '股票被分類在風險或弱勢，但報告語氣明顯偏樂觀，分類與使用者看到的結論不一致。',
            ];
        }

        if (in_array($cardType, ['priority', 'potential', 'low_volume'], true) && ($risk + $bear) >= max(4, $bull + 3)) {
            return [
                'severity' => 'medium',
                'description' => '股票被分類在觀察或潛力類，但報告風險語氣過重，可能需要確認分類條件或報告模板。',
            ];
        }

        if ($cardType === 'risk' && ! Str::contains($summary, ['風險', '估值', '籌碼', '賣壓', '拉回', '震盪'])) {
            return [
                'severity' => 'medium',
                'description' => '風險升高股的報告沒有明確說明風險來源。',
            ];
        }

        return null;
    }

    private function looksTooGeneric(string $summary): bool
    {
        $numericHits = preg_match_all('/\d+(?:\.\d+)?%?/u', $summary);
        $specificTerms = $this->termHits($summary, [
            '近 5 日', '近 20 日', '量能', 'MACD', 'KD', 'RSI', '外資', '投信', '融資', '營收', 'EPS', 'ROE', '毛利率', '本益比',
        ]);

        return $numericHits < 3 || count($specificTerms) < 4;
    }

    /**
     * @return array<int, string>
     */
    private function termHits(string $text, array $terms): array
    {
        return collect($terms)
            ->filter(fn (string $term) => Str::contains($text, $term))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function bullTerms(): array
    {
        return ['偏向多方', '買方掌握', '轉強', '上漲條件', '支撐', '動能改善', '題材升溫', '法人買盤', '營收成長', '多方結構'];
    }

    /**
     * @return array<int, string>
     */
    private function riskTerms(): array
    {
        return ['風險', '估值', '漲幅已經', '賣壓', '拉回', '震盪', '籌碼浮動', '乖離率', '過熱', '領先基本面'];
    }

    /**
     * @return array<int, string>
     */
    private function bearTerms(): array
    {
        return ['弱勢', '跌破', '轉弱', '均線下方', '跌深', '保守', '賣壓仍在', '下跌結構'];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function finding(object $report, string $severity, string $type, string $title, string $description, array $payload, ?string $recommendation = null): int
    {
        $lookup = [
            'agent_role_id' => $this->role->id,
            'status' => 'pending',
            'finding_type' => $type,
            'page' => 'stock',
            'symbol' => (string) $report->symbol,
            'title' => $title,
        ];

        $values = [
            'agent_run_id' => $this->run?->id,
            'severity' => $severity,
            'theme_slug' => null,
            'description' => $description,
            'evidence' => 'report_id='.$report->report_id.', model='.$report->model.', report_date='.$report->report_date,
            'recommendation' => $recommendation,
            'payload' => array_merge($payload, [
                'stock_id' => $report->stock_id,
                'symbol' => $report->symbol,
                'name' => $report->name,
                'card_type' => $report->card_type,
                'audit_date' => $this->auditDate,
            ]),
            'updated_at' => now(),
        ];

        $existing = AgentFinding::query()
            ->where($lookup)
            ->whereBetween('created_at', [
                CarbonImmutable::parse($this->auditDate, 'Asia/Taipei')->startOfDay()->utc(),
                CarbonImmutable::parse($this->auditDate, 'Asia/Taipei')->endOfDay()->utc(),
            ])
            ->first();

        if ($existing) {
            $existing->update($values);

            return 0;
        }

        AgentFinding::query()->create(array_merge($lookup, $values, ['created_at' => now()]));

        return 1;
    }

    private function finishRun(string $status, int $findings, string $summary): void
    {
        $started = $this->run?->started_at ? CarbonImmutable::parse($this->run->started_at) : CarbonImmutable::now();

        $this->run?->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round($started->diffInMilliseconds(now())),
            'findings_count' => $findings,
            'summary' => $summary,
            'output_context' => [
                'audit_date' => $this->auditDate,
                'findings' => $findings,
            ],
        ]);

        $this->info($summary);
    }
}
