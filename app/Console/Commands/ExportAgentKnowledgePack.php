<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentMemory;
use App\Models\AgentRun;
use App\Models\MarketDailyContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ExportAgentKnowledgePack extends Command
{
    protected $signature = 'market:export-agent-knowledge-pack
        {--date= : Pack date, default today in Asia/Taipei}
        {--path= : Output JSON path, default storage/app/agent_packs/marketx-agent-pack-latest.json}';

    protected $description = 'Export a clean JSON knowledge pack for local AI agents such as local Lobster/Ollama.';

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();

        $defaultPath = storage_path('app/agent_packs/marketx-agent-pack-latest.json');
        $path = (string) ($this->option('path') ?: $defaultPath);
        $datedPath = storage_path('app/agent_packs/marketx-agent-pack-'.$date.'.json');

        $pack = [
            'schema_version' => 'marketx-agent-pack.v1',
            'generated_at' => CarbonImmutable::now('Asia/Taipei')->toDateTimeString(),
            'pack_date' => $date,
            'mission' => [
                'system' => '股市在幹嘛',
                'purpose' => '提供本機 AI 代理人每日巡檢所需的乾淨資料，讓代理人判斷頁面、分類規則、資料更新與市場解讀是否合理。',
                'important_rules' => [
                    'AI 不預測價格，不下買賣指令，只檢查資料、邏輯、解釋與風險提示是否合理。',
                    '若發現問題，請提出案件編號、證據、影響範圍、建議修正方向，讓 Codex 可以接手處理。',
                    '所有判斷都要盡量根據資料表、歷史驗證、技術籌碼財務題材資料，不要憑感覺。',
                    '若資料不足，請標明資料缺口，不要硬做結論。',
                ],
            ],
            'market_context' => $this->marketContext($date),
            'agent_findings' => $this->agentFindings(),
            'recent_agent_runs' => $this->recentAgentRuns(),
            'active_memories' => $this->activeMemories(),
            'radar_performance' => $this->radarPerformance(),
            'data_freshness' => $this->dataFreshness(),
            'expected_output' => [
                'daily_agent_brief' => [
                    '今日最需要處理的問題',
                    '今日最需要觀察的規則',
                    '五張卡分類與後續表現是否合理',
                    '建議 Codex 修改的優先順序',
                    '資料缺口與不可下結論的地方',
                ],
            ],
        ];

        $json = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            $this->error('Failed to encode agent knowledge pack.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json.PHP_EOL);

        if ($datedPath !== $path) {
            File::ensureDirectoryExists(dirname($datedPath));
            File::put($datedPath, $json.PHP_EOL);
        }

        $this->info('Agent knowledge pack exported.');
        $this->line('Latest: '.$path);
        $this->line('Dated: '.$datedPath);

        return self::SUCCESS;
    }

    private function marketContext(string $date): ?array
    {
        $context = MarketDailyContext::query()
            ->where('context_date', '<=', $date)
            ->orderByDesc('context_date')
            ->orderByRaw("case session when 'night' then 4 when 'aftermarket' then 3 when 'premarket' then 2 when 'daily' then 1 else 0 end desc")
            ->orderByDesc('updated_at')
            ->first();

        if (! $context) {
            return null;
        }

        return [
            'id' => $context->id,
            'context_date' => optional($context->context_date)->toDateString(),
            'session' => $context->session,
            'market_phase' => $context->market_phase,
            'risk_score' => $context->risk_score,
            'opportunity_score' => $context->opportunity_score,
            'summary' => $context->summary,
            'top_themes' => collect($context->theme_snapshot ?? [])->take(10)->values()->all(),
            'radar_snapshot' => $context->radar_snapshot,
            'global_markets' => collect($context->global_markets ?? [])->take(30)->values()->all(),
            'ai_reports' => $context->ai_reports,
            'freshness' => $context->freshness,
            'updated_at' => $this->timeString($context->updated_at),
        ];
    }

    private function agentFindings(): array
    {
        return AgentFinding::query()
            ->with('role:id,name,slug')
            ->whereIn('status', ['pending', 'observing'])
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AgentFinding $finding) => [
                'case_no' => $this->caseNo($finding),
                'status' => $finding->status,
                'severity' => $finding->severity,
                'finding_type' => $finding->finding_type,
                'agent' => $finding->role ? [
                    'slug' => $finding->role->slug,
                    'name' => $finding->role->name,
                ] : null,
                'page' => $finding->page,
                'symbol' => $finding->symbol,
                'theme_slug' => $finding->theme_slug,
                'title' => $finding->title,
                'description' => $finding->description,
                'evidence' => $finding->evidence,
                'recommendation' => $finding->recommendation,
                'codex_feedback' => $finding->codex_feedback,
                'payload' => $finding->payload,
                'created_at' => $this->timeString($finding->created_at),
                'updated_at' => $this->timeString($finding->updated_at),
            ])
            ->values()
            ->all();
    }

    private function recentAgentRuns(): array
    {
        return AgentRun::query()
            ->with('role:id,name,slug')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (AgentRun $run) => [
                'agent' => $run->role ? [
                    'slug' => $run->role->slug,
                    'name' => $run->role->name,
                ] : null,
                'status' => $run->status,
                'findings_count' => $run->findings_count,
                'memories_count' => $run->memories_count,
                'summary' => $run->summary,
                'input_context' => $run->input_context,
                'output_context' => $run->output_context,
                'started_at' => $this->timeString($run->started_at),
                'finished_at' => $this->timeString($run->finished_at),
            ])
            ->values()
            ->all();
    }

    private function activeMemories(): array
    {
        return AgentMemory::query()
            ->with('role:id,name,slug')
            ->where('status', 'active')
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get()
            ->map(fn (AgentMemory $memory) => [
                'id' => $memory->id,
                'role' => $memory->role ? [
                    'slug' => $memory->role->slug,
                    'name' => $memory->role->name,
                ] : null,
                'memory_type' => $memory->memory_type,
                'title' => $memory->title,
                'rule_summary' => $memory->rule_summary,
                'correct_pattern' => $memory->correct_pattern,
                'wrong_pattern' => $memory->wrong_pattern,
                'codex_feedback' => $memory->codex_feedback,
                'confidence' => $memory->confidence,
                'usage_count' => $memory->usage_count,
                'payload' => $memory->payload,
                'updated_at' => $this->timeString($memory->updated_at),
            ])
            ->values()
            ->all();
    }

    private function radarPerformance(): array
    {
        if (! $this->hasTable('stock_radar_observations') || ! $this->hasTable('stock_radar_observation_checks')) {
            return ['available' => false];
        }

        $byCard = DB::table('stock_radar_observation_checks as c')
            ->join('stock_radar_observations as o', 'o.id', '=', 'c.stock_radar_observation_id')
            ->whereIn('c.days_since_selected', [1, 3, 5])
            ->groupBy('o.card_type', 'c.days_since_selected')
            ->orderBy('o.card_type')
            ->orderBy('c.days_since_selected')
            ->get([
                'o.card_type',
                'c.days_since_selected',
                DB::raw('count(*) as total'),
                DB::raw('count(c.change_pct) as valid_count'),
                DB::raw('round(avg(c.change_pct), 2) as avg_change_pct'),
                DB::raw('sum(case when c.change_pct > 0 then 1 else 0 end) as up_count'),
                DB::raw('sum(case when c.change_pct < 0 then 1 else 0 end) as down_count'),
                DB::raw('round(max(c.change_pct), 2) as max_change_pct'),
                DB::raw('round(min(c.change_pct), 2) as min_change_pct'),
            ])
            ->map(fn (object $row) => [
                'card_type' => $row->card_type,
                'days_since_selected' => (int) $row->days_since_selected,
                'total' => (int) $row->total,
                'valid_count' => (int) $row->valid_count,
                'avg_change_pct' => $row->avg_change_pct === null ? null : (float) $row->avg_change_pct,
                'up_count' => (int) $row->up_count,
                'down_count' => (int) $row->down_count,
                'max_change_pct' => $row->max_change_pct === null ? null : (float) $row->max_change_pct,
                'min_change_pct' => $row->min_change_pct === null ? null : (float) $row->min_change_pct,
            ])
            ->values()
            ->all();

        $recentPicks = DB::table('stock_radar_observations as o')
            ->join('stocks as s', 's.id', '=', 'o.stock_id')
            ->leftJoin('stock_radar_observation_checks as c', function ($join) {
                $join->on('c.stock_radar_observation_id', '=', 'o.id')
                    ->whereRaw('c.check_date = (select max(c2.check_date) from stock_radar_observation_checks c2 where c2.stock_radar_observation_id = o.id)');
            })
            ->orderByDesc('o.selected_date')
            ->orderBy('o.card_type')
            ->orderBy('o.entry_rank')
            ->limit(40)
            ->get([
                'o.selected_date',
                'o.card_type',
                'o.entry_rank',
                'o.entry_confidence',
                'o.entry_reasons',
                'o.status',
                's.symbol',
                's.name',
                'c.check_date',
                'c.days_since_selected',
                'c.close',
                'c.change_pct',
                'c.condition_still_present',
            ])
            ->map(fn (object $row) => [
                'selected_date' => (string) $row->selected_date,
                'card_type' => $row->card_type,
                'rank' => (int) $row->entry_rank,
                'confidence' => (int) $row->entry_confidence,
                'symbol' => $row->symbol,
                'name' => $row->name,
                'status' => $row->status,
                'reasons' => $this->reasonLabels($row->entry_reasons),
                'latest_check' => [
                    'check_date' => $row->check_date,
                    'days_since_selected' => $row->days_since_selected === null ? null : (int) $row->days_since_selected,
                    'close' => $row->close === null ? null : (float) $row->close,
                    'change_pct' => $row->change_pct === null ? null : (float) $row->change_pct,
                    'condition_still_present' => (bool) $row->condition_still_present,
                ],
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'by_card_and_horizon' => $byCard,
            'recent_picks' => $recentPicks,
        ];
    }

    private function dataFreshness(): array
    {
        $tables = [
            'stock_prices_1d' => 'trade_date',
            'stock_scores' => 'score_date',
            'stock_technical_indicators_1d' => 'trade_date',
            'stock_chips_1d' => 'trade_date',
            'theme_scores' => 'score_date',
            'stock_radar_cards' => 'card_date',
            'stock_radar_observations' => 'selected_date',
            'stock_radar_observation_checks' => 'check_date',
            'global_market_data' => 'trade_date',
            'global_event_clusters' => 'cluster_date',
        ];

        return collect($tables)
            ->filter(fn (string $dateColumn, string $table) => $this->hasTable($table))
            ->mapWithKeys(fn (string $dateColumn, string $table) => [$table => [
                'latest' => DB::table($table)->max($dateColumn),
                'updated_at' => DB::table($table)->max('updated_at'),
                'count' => DB::table($table)->count(),
            ]])
            ->all();
    }

    private function caseNo(AgentFinding $finding): string
    {
        return 'AG-'.CarbonImmutable::parse($finding->created_at)
            ->timezone('Asia/Taipei')
            ->format('Ymd')
            .'-'.str_pad((string) $finding->id, 5, '0', STR_PAD_LEFT);
    }

    private function hasTable(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function reasonLabels(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return collect(is_array($decoded) ? $decoded : [])
            ->map(fn ($reason) => is_array($reason) ? ($reason['label'] ?? null) : null)
            ->filter()
            ->values()
            ->all();
    }

    private function timeString(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }

        return CarbonImmutable::parse($time)->timezone('Asia/Taipei')->toDateTimeString();
    }
}
