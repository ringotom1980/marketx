<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ListLatestAgentRuns extends Command
{
    protected $signature = 'market:agents-latest-runs
        {--limit=10 : Number of runs to show}
        {--agent= : Filter by agent role slug}
        {--json : Output JSON}';

    protected $description = 'List recent MarketX agent run records.';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = AgentRun::query()
            ->with('role:id,slug,name')
            ->latest('id');

        $agent = trim((string) $this->option('agent'));
        if ($agent !== '') {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('slug', $agent));
        }

        $runs = $query->limit($limit)->get();

        if ($this->option('json')) {
            $this->line(json_encode($runs->map(fn (AgentRun $run) => $this->serializeRun($run))->values(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($runs->isEmpty()) {
            $this->info('目前沒有符合條件的代理人執行紀錄。');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', '時間', '代理人', '狀態', '耗時秒', '案件', '記憶', '摘要/錯誤'],
            $runs->map(fn (AgentRun $run) => [
                $run->id,
                $run->started_at ? CarbonImmutable::parse($run->started_at)->timezone('Asia/Taipei')->format('m/d H:i:s') : '-',
                $run->role?->slug ?? '-',
                $run->status,
                $run->duration_ms === null ? '-' : number_format($run->duration_ms / 1000, 1),
                $run->findings_count,
                $run->memories_count,
                Str::limit((string) ($run->error_message ?: $run->summary), 70),
            ])->all()
        );

        foreach ($runs as $run) {
            $this->line('');
            $this->line('Run #'.$run->id.' '.$run->status.' / '.($run->role?->slug ?? '-'));
            if ($run->summary) {
                $this->line('摘要：'.$this->compactText($run->summary));
            }
            if ($run->error_message) {
                $this->line('錯誤：'.$this->compactText($run->error_message));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeRun(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent' => [
                'slug' => $run->role?->slug,
                'name' => $run->role?->name,
            ],
            'status' => $run->status,
            'started_at' => $run->started_at ? CarbonImmutable::parse($run->started_at)->timezone('Asia/Taipei')->toDateTimeString() : null,
            'finished_at' => $run->finished_at ? CarbonImmutable::parse($run->finished_at)->timezone('Asia/Taipei')->toDateTimeString() : null,
            'duration_ms' => $run->duration_ms,
            'findings_count' => $run->findings_count,
            'memories_count' => $run->memories_count,
            'summary' => $run->summary,
            'error_message' => $run->error_message,
            'input_context' => $run->input_context ?? [],
            'output_context' => $run->output_context ?? [],
        ];
    }

    private function compactText(?string $value): string
    {
        return Str::limit(preg_replace('/\s+/u', ' ', trim((string) $value)) ?: '-', 220);
    }
}
