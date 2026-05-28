<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ListLatestAgentFindings extends Command
{
    protected $signature = 'market:agents-latest-findings
        {--limit=10 : Number of findings to show}
        {--status= : Filter by finding status}
        {--agent= : Filter by agent role slug}
        {--date= : Filter by created date in Asia/Taipei, YYYY-MM-DD}
        {--json : Output JSON for local agent runners}';

    protected $description = 'List recent MarketX agent findings through Laravel instead of fragile shell SQL.';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = AgentFinding::query()
            ->with('role:id,slug,name')
            ->latest('id');

        if ($status = $this->normalizedOption('status')) {
            $query->where('status', $status);
        }

        if ($agent = $this->normalizedOption('agent')) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('slug', $agent));
        }

        if ($date = $this->normalizedOption('date')) {
            $start = CarbonImmutable::parse($date, 'Asia/Taipei')->startOfDay()->utc();
            $end = $start->timezone('Asia/Taipei')->endOfDay()->utc();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $findings = $query->limit($limit)->get();

        if ($this->option('json')) {
            $this->line(json_encode(
                $findings->map(fn (AgentFinding $finding) => $this->serializeFinding($finding))->values(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));

            return self::SUCCESS;
        }

        if ($findings->isEmpty()) {
            $this->info('目前沒有符合條件的代理人案件。');

            return self::SUCCESS;
        }

        $this->table(
            ['案件編號', '時間', '代理人', '狀態', '等級', '類型', '頁面/股票', '標題'],
            $findings->map(fn (AgentFinding $finding) => [
                $this->caseNo($finding),
                CarbonImmutable::parse($finding->created_at)->timezone('Asia/Taipei')->format('m/d H:i'),
                $finding->role?->slug ?? '-',
                $finding->status,
                $finding->severity,
                $finding->finding_type,
                trim(($finding->page ?: '-').($finding->symbol ? ' / '.$finding->symbol : '')),
                Str::limit($finding->title, 34),
            ])->all()
        );

        foreach ($findings as $finding) {
            $this->line('');
            $this->line($this->caseNo($finding).' '.$finding->title);
            $this->line('說明：'.$this->compactText($finding->description));

            if ($finding->evidence) {
                $this->line('證據：'.$this->compactText($finding->evidence));
            }

            if ($finding->recommendation) {
                $this->line('建議：'.$this->compactText($finding->recommendation));
            }

            if ($finding->codex_feedback) {
                $this->line('處理紀錄：'.$this->compactText($finding->codex_feedback));
            }
        }

        return self::SUCCESS;
    }

    private function normalizedOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeFinding(AgentFinding $finding): array
    {
        return [
            'case_no' => $this->caseNo($finding),
            'id' => $finding->id,
            'created_at' => CarbonImmutable::parse($finding->created_at)->timezone('Asia/Taipei')->toDateTimeString(),
            'agent' => [
                'slug' => $finding->role?->slug,
                'name' => $finding->role?->name,
            ],
            'status' => $finding->status,
            'severity' => $finding->severity,
            'finding_type' => $finding->finding_type,
            'page' => $finding->page,
            'symbol' => $finding->symbol,
            'theme_slug' => $finding->theme_slug,
            'title' => $finding->title,
            'description' => $finding->description,
            'evidence' => $finding->evidence,
            'recommendation' => $finding->recommendation,
            'codex_feedback' => $finding->codex_feedback,
            'payload' => $finding->payload ?? [],
        ];
    }

    private function caseNo(AgentFinding $finding): string
    {
        return 'AG-'.CarbonImmutable::parse($finding->created_at)
            ->timezone('Asia/Taipei')
            ->format('Ymd')
            .'-'.str_pad((string) $finding->id, 5, '0', STR_PAD_LEFT);
    }

    private function compactText(?string $value): string
    {
        return Str::limit(preg_replace('/\s+/u', ' ', trim((string) $value)) ?: '-', 180);
    }
}
