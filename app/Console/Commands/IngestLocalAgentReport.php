<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentMemory;
use App\Models\AgentRole;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class IngestLocalAgentReport extends Command
{
    protected $signature = 'market:agents-ingest-local-report
        {path : Local agent JSON report path}
        {--agent=local-lobster : Agent slug used when report does not specify one}
        {--dry-run : Validate and preview without writing to database}';

    protected $description = 'Ingest a JSON report produced by a local AI agent and write findings/memories to the agent tables.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! File::exists($path)) {
            $this->error('Report file not found: '.$path);

            return self::FAILURE;
        }

        $report = json_decode((string) File::get($path), true);

        if (! is_array($report)) {
            $this->error('Report must be valid JSON object.');

            return self::FAILURE;
        }

        $agent = $this->agentFromReport($report);
        $findings = $this->normalizeFindings(Arr::get($report, 'findings', []));
        $memories = $this->normalizeMemories(Arr::get($report, 'memories', []));
        $summary = Str::limit((string) Arr::get($report, 'summary', '本機代理人巡檢完成。'), 1000, '');

        if ($this->option('dry-run')) {
            $this->info('Local agent report is valid.');
            $this->line('Agent: '.$agent->slug.' / '.$agent->name);
            $this->line('Findings: '.count($findings));
            $this->line('Memories: '.count($memories));

            return self::SUCCESS;
        }

        $run = AgentRun::query()->create([
            'agent_role_id' => $agent->id,
            'run_key' => $agent->slug.':local:'.now('Asia/Taipei')->format('YmdHis'),
            'status' => 'running',
            'started_at' => now(),
            'summary' => $summary,
            'input_context' => [
                'source' => 'local_agent_report',
                'path' => $path,
                'report_generated_at' => Arr::get($report, 'generated_at'),
                'pack_date' => Arr::get($report, 'pack_date'),
            ],
        ]);

        $createdFindings = 0;
        $createdMemories = 0;

        foreach ($findings as $finding) {
            $createdFindings += $this->upsertFinding($agent, $run, $finding);
        }

        foreach ($memories as $memory) {
            $this->upsertMemory($agent, $memory);
            $createdMemories++;
        }

        $started = $run->started_at ? CarbonImmutable::parse($run->started_at) : CarbonImmutable::now();

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'duration_ms' => (int) round($started->diffInMilliseconds(now())),
            'findings_count' => $createdFindings,
            'memories_count' => $createdMemories,
            'output_context' => [
                'findings_imported' => $createdFindings,
                'memories_imported' => $createdMemories,
            ],
        ]);

        $this->info('Local agent report ingested.');
        $this->line('Run ID: '.$run->id);
        $this->line('Findings imported: '.$createdFindings);
        $this->line('Memories imported: '.$createdMemories);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function agentFromReport(array $report): AgentRole
    {
        $slug = (string) (Arr::get($report, 'agent.slug') ?: $this->option('agent') ?: 'local-lobster');
        $name = (string) (Arr::get($report, 'agent.name') ?: '本機龍蝦代理人');

        return AgentRole::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'scope' => '本機 AI 代理人回報',
                'mission' => '讀取 Agent Knowledge Pack，提出資料、規則、頁面與市場解讀問題，交由 Codex 修正。',
                'is_active' => true,
                'settings' => ['source' => 'local_agent'],
            ]
        );
    }

    /**
     * @param mixed $items
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFindings(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));

                if ($title === '' || $description === '') {
                    return null;
                }

                return [
                    'severity' => $this->allowed((string) ($item['severity'] ?? 'info'), ['critical', 'high', 'medium', 'low', 'info'], 'info'),
                    'finding_type' => Str::slug((string) ($item['finding_type'] ?? $item['type'] ?? 'local_agent_observation'), '_'),
                    'page' => $this->nullableString($item['page'] ?? null),
                    'symbol' => $this->nullableString($item['symbol'] ?? null),
                    'theme_slug' => $this->nullableString($item['theme_slug'] ?? null),
                    'title' => Str::limit($title, 250, ''),
                    'description' => $description,
                    'evidence' => $this->nullableString($item['evidence'] ?? null),
                    'recommendation' => $this->nullableString($item['recommendation'] ?? null),
                    'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : ['source_item' => $item],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param mixed $items
     * @return array<int,array<string,mixed>>
     */
    private function normalizeMemories(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $title = trim((string) ($item['title'] ?? ''));

                if ($title === '') {
                    return null;
                }

                return [
                    'memory_type' => $this->allowed((string) ($item['memory_type'] ?? 'rule'), ['rule', 'pattern', 'data_gap', 'review_note'], 'rule'),
                    'title' => Str::limit($title, 250, ''),
                    'rule_summary' => $this->nullableString($item['rule_summary'] ?? $item['summary'] ?? null),
                    'correct_pattern' => $this->nullableString($item['correct_pattern'] ?? null),
                    'wrong_pattern' => $this->nullableString($item['wrong_pattern'] ?? null),
                    'codex_feedback' => $this->nullableString($item['codex_feedback'] ?? null),
                    'confidence' => max(1, min(100, (int) ($item['confidence'] ?? 70))),
                    'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : ['source_item' => $item],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $finding
     */
    private function upsertFinding(AgentRole $agent, AgentRun $run, array $finding): int
    {
        $lookup = [
            'agent_role_id' => $agent->id,
            'status' => 'pending',
            'finding_type' => $finding['finding_type'],
            'page' => $finding['page'],
            'symbol' => $finding['symbol'],
            'title' => $finding['title'],
        ];

        $values = [
            'agent_run_id' => $run->id,
            'severity' => $finding['severity'],
            'theme_slug' => $finding['theme_slug'],
            'description' => $finding['description'],
            'evidence' => $finding['evidence'],
            'recommendation' => $finding['recommendation'],
            'payload' => array_merge($finding['payload'], [
                'source' => 'local_agent_report',
                'run_id' => $run->id,
            ]),
            'updated_at' => now(),
        ];

        $existing = AgentFinding::query()
            ->where($lookup)
            ->whereDate('created_at', now('Asia/Taipei')->toDateString())
            ->first();

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
     * @param array<string,mixed> $memory
     */
    private function upsertMemory(AgentRole $agent, array $memory): void
    {
        AgentMemory::query()->updateOrCreate(
            [
                'agent_role_id' => $agent->id,
                'memory_type' => $memory['memory_type'],
                'title' => $memory['title'],
            ],
            [
                'status' => 'active',
                'rule_summary' => $memory['rule_summary'],
                'correct_pattern' => $memory['correct_pattern'],
                'wrong_pattern' => $memory['wrong_pattern'],
                'codex_feedback' => $memory['codex_feedback'],
                'confidence' => $memory['confidence'],
                'payload' => array_merge($memory['payload'], ['source' => 'local_agent_report']),
                'last_used_at' => now(),
            ]
        );
    }

    /**
     * @param array<int,string> $allowed
     */
    private function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
