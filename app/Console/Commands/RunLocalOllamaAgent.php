<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use App\Models\AgentRole;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunLocalOllamaAgent extends Command
{
    protected $signature = 'market:agents-run-ollama
        {--model=qwen2.5:1.5b : Ollama model name}
        {--limit=5 : Number of recent findings to review}
        {--timeout=90 : Ollama request timeout seconds}
        {--dry-run : Preview prompt without calling Ollama}';

    protected $description = 'Run a conservative local Ollama agent review against recent MarketX agent findings.';

    private const OLLAMA_URL = 'http://127.0.0.1:11434';

    public function handle(): int
    {
        $limit = max(1, min(10, (int) $this->option('limit')));
        $timeout = max(10, min(900, (int) $this->option('timeout')));
        $model = trim((string) $this->option('model')) ?: 'qwen2.5:1.5b';

        $agent = AgentRole::query()->firstOrCreate(
            ['slug' => 'local-ollama'],
            [
                'name' => 'VPS 本機 Ollama 代理人',
                'scope' => '凌晨背景檢查代理人案件與規則合理性',
                'mission' => '讀取 MarketX 代理人案件，提出可驗證、可追蹤、保守的修正建議。',
                'is_active' => true,
                'settings' => ['runtime' => 'ollama', 'default_model' => $model],
            ]
        );
        $this->markStaleRuns($agent);

        $findings = AgentFinding::query()
            ->with('role:id,slug,name')
            ->whereIn('status', ['pending', 'observing'])
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($findings->isEmpty()) {
            $this->info('目前沒有 pending / observing 案件需要 Ollama 檢查。');

            return self::SUCCESS;
        }

        $prompt = $this->buildPrompt($findings);

        if ($this->option('dry-run')) {
            $this->line($prompt);

            return self::SUCCESS;
        }

        $run = AgentRun::query()->create([
            'agent_role_id' => $agent->id,
            'run_key' => $agent->slug.':'.now('Asia/Taipei')->format('YmdHis'),
            'status' => 'running',
            'started_at' => now(),
            'summary' => 'Ollama reviewing latest agent findings.',
            'input_context' => [
                'model' => $model,
                'timeout' => $timeout,
                'finding_ids' => $findings->pluck('id')->values()->all(),
            ],
        ]);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->post(self::OLLAMA_URL.'/api/generate', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.2,
                        'num_ctx' => 2048,
                    ],
                ]);
        } catch (\Throwable $exception) {
            return $this->failRun($run, 'Ollama 呼叫失敗：'.$exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failRun($run, 'Ollama HTTP '.$response->status().'：'.Str::limit($response->body(), 500));
        }

        try {
            $raw = (string) $response->json('response', '');
            $report = $this->extractJson($raw);

            if (! is_array($report)) {
                return $this->failRun($run, 'Ollama 回覆不是可解析 JSON：'.Str::limit($raw, 500));
            }

            $createdFindings = $this->writeFindings($agent, $run, $report);
            $summary = Str::limit((string) ($report['summary'] ?? 'Ollama review completed.'), 1000, '');
            $started = CarbonImmutable::parse($run->started_at);

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => (int) round($started->diffInMilliseconds(now())),
                'findings_count' => $createdFindings,
                'summary' => $summary,
                'output_context' => [
                    'raw_response' => $raw,
                    'parsed_report' => $report,
                    'findings_imported' => $createdFindings,
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->failRun($run, 'Ollama 回覆處理失敗：'.$exception->getMessage());
        }

        $this->info('Ollama 代理人檢查完成。');
        $this->line('Model: '.$model);
        $this->line('Run ID: '.$run->id);
        $this->line('新增案件: '.$createdFindings);
        $this->line('摘要: '.$summary);

        return self::SUCCESS;
    }

    private function buildPrompt($findings): string
    {
        $cases = $findings->map(function (AgentFinding $finding) {
            return [
                'case_no' => $this->caseNo($finding),
                'agent' => $finding->role?->slug,
                'status' => $finding->status,
                'severity' => $finding->severity,
                'type' => $finding->finding_type,
                'page' => $finding->page,
                'symbol' => $finding->symbol,
                'title' => $finding->title,
                'description' => Str::limit((string) $finding->description, 260, ''),
                'evidence' => Str::limit((string) $finding->evidence, 220, ''),
                'recommendation' => Str::limit((string) $finding->recommendation, 220, ''),
            ];
        })->values()->all();

        $json = json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
你是《股市在幹嘛》的 VPS 本機代理人。你的任務是檢查代理人案件是否合理，並提出保守、可驗證的工程修正方向。

重要限制：
1. 不做股價預測，不給買賣建議。
2. 不要幻想資料來源；只能根據案件內容判斷。
3. 如果資訊不足，請說需要補哪些資料。
4. 回覆必須是單一 JSON object，不要 Markdown，不要額外說明。
5. 如果只能重複原案件內容，或沒有引用 AG- 開頭的案件編號，findings 必須回傳空陣列。
6. page 只能填 home、themes、global、stock、admin 或 null。

JSON 格式：
{
  "summary": "用繁體中文 80 字內摘要本次檢查重點",
  "findings": [
    {
      "severity": "low|medium|high",
      "finding_type": "local_ollama_review",
      "page": "home|themes|global|stock|admin|null",
      "symbol": null,
      "title": "繁體中文標題",
      "description": "說明你看到的問題或確認事項",
      "evidence": "引用案件編號與既有證據",
      "recommendation": "給 Codex 的具體修正建議"
    }
  ]
}

若沒有新問題，findings 請回傳空陣列。

待檢查案件：
{$json}
PROMPT;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function writeFindings(AgentRole $agent, AgentRun $run, array $report): int
    {
        $items = collect($report['findings'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->take(5);

        $created = 0;

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));

            if ($title === '' || $description === '') {
                continue;
            }

            $evidence = $this->nullableString($item['evidence'] ?? null);

            if (! $evidence || ! str_contains($evidence, 'AG-')) {
                continue;
            }

            $lookup = [
                'agent_role_id' => $agent->id,
                'status' => 'pending',
                'finding_type' => Str::slug((string) ($item['finding_type'] ?? 'local_ollama_review'), '_'),
                'page' => $this->allowedPage($item['page'] ?? null),
                'symbol' => $this->nullableString($item['symbol'] ?? null),
                'title' => Str::limit($title, 250, ''),
            ];

            $existing = AgentFinding::query()
                ->where($lookup)
                ->whereDate('created_at', now('Asia/Taipei')->toDateString())
                ->first();

            $values = [
                'agent_run_id' => $run->id,
                'severity' => $this->allowed((string) ($item['severity'] ?? 'low'), ['critical', 'high', 'medium', 'low', 'info'], 'low'),
                'theme_slug' => $this->nullableString($item['theme_slug'] ?? null),
                'description' => $description,
                'evidence' => $evidence,
                'recommendation' => $this->nullableString($item['recommendation'] ?? null),
                'payload' => [
                    'source' => 'local_ollama',
                    'run_id' => $run->id,
                    'raw_item' => $item,
                ],
                'updated_at' => now(),
            ];

            if ($existing) {
                $existing->update($values);

                continue;
            }

            AgentFinding::query()->create(array_merge($lookup, $values, [
                'created_at' => now(),
            ]));

            $created++;
        }

        return $created;
    }

    private function failRun(AgentRun $run, string $message): int
    {
        $started = $run->started_at ? CarbonImmutable::parse($run->started_at) : CarbonImmutable::now();

        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_ms' => (int) round($started->diffInMilliseconds(now())),
            'error_message' => $message,
        ]);

        $this->error($message);

        return self::FAILURE;
    }

    private function markStaleRuns(AgentRole $agent): void
    {
        AgentRun::query()
            ->where('agent_role_id', $agent->id)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(12))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => 'Stale Ollama run recovered before next execution.',
            ]);
    }

    private function caseNo(AgentFinding $finding): string
    {
        return 'AG-'.CarbonImmutable::parse($finding->created_at)
            ->timezone('Asia/Taipei')
            ->format('Ymd')
            .'-'.str_pad((string) $finding->id, 5, '0', STR_PAD_LEFT);
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
        if (is_array($value) || is_object($value)) {
            return null;
        }

        if ($value === null || $value === 'null') {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function allowedPage(mixed $value): ?string
    {
        $text = $this->nullableString($value);

        return in_array($text, ['home', 'themes', 'global', 'stock', 'admin'], true) ? $text : null;
    }
}
