<?php

namespace App\Console\Commands;

use App\Support\Ai\AiPipelineService;
use App\Support\Ai\AiUsageLimiter;
use App\Support\Ai\GroqProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiPreprocessEvents extends Command
{
    protected $signature = 'market:ai-preprocess-events
        {--limit=5 : Number of latest event clusters to include}
        {--live : Actually call Groq. Without this option, only builds the prompt and logs a skipped result}';

    protected $description = 'Use Groq as the AI v3 event preprocessing layer for summaries, sentiment, tags, and theme classification.';

    public function handle(GroqProvider $groq, AiPipelineService $pipeline, AiUsageLimiter $limiter): int
    {
        $task = 'event_preprocess';
        $limit = max(1, min(20, (int) $this->option('limit')));
        $live = (bool) $this->option('live');

        if (! $limiter->canRun($task)) {
            $this->warn('Daily AI limit reached for '.$task);
            return self::SUCCESS;
        }

        $events = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit($limit)
            ->get(['id', 'cluster_date', 'cluster_key', 'title', 'summary', 'category', 'region', 'importance_score', 'sentiment', 'themes', 'industries', 'related_symbols']);

        if ($events->isEmpty()) {
            $this->warn('No global event clusters available. Run market:cluster-global-events first.');
            return self::SUCCESS;
        }

        $prompt = $this->prompt($events->toArray());
        $result = $groq->chat($prompt, $live);
        $pipeline->log($task, $result, $prompt);

        if (! $result->ok) {
            $this->warn('Groq preprocessing skipped/failed: '.$result->error);
            return self::SUCCESS;
        }

        $processed = $this->decodeJsonArray((string) $result->text);
        $updated = 0;

        foreach ($processed as $row) {
            $clusterId = (int) ($row['cluster_id'] ?? 0);

            if ($clusterId <= 0) {
                continue;
            }

            $updated += DB::table('global_event_clusters')
                ->where('id', $clusterId)
                ->update([
                    'summary' => $row['summary_zh'] ?? DB::raw('summary'),
                    'sentiment' => $row['sentiment'] ?? DB::raw('sentiment'),
                    'themes' => isset($row['themes']) ? json_encode($row['themes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : DB::raw('themes'),
                    'industries' => isset($row['industries']) ? json_encode($row['industries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : DB::raw('industries'),
                    'related_symbols' => isset($row['related_symbols']) ? json_encode($row['related_symbols'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : DB::raw('related_symbols'),
                    'importance_score' => isset($row['importance_score']) ? max(0, min(100, (int) $row['importance_score'])) : DB::raw('importance_score'),
                    'ai_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ai_status' => 'groq_processed',
                    'updated_at' => now(),
                ]);
        }

        DB::table('system_logs')->insert([
            'level' => 'info',
            'source' => 'AI Groq Preprocess',
            'message' => 'Groq event preprocessing completed.',
            'context' => json_encode([
                'event_count' => $events->count(),
                'updated_clusters' => $updated,
                'model' => $result->model,
                'text' => $result->text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Groq event preprocessing completed.');
        $this->line('Updated clusters: '.$updated);
        $this->line((string) $result->text);

        return self::SUCCESS;
    }

    private function decodeJsonArray(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^```\s*/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (is_array($decoded)) {
            return array_is_list($decoded) ? $decoded : [$decoded];
        }

        if (preg_match('/(\[[\s\S]*\])/', $text, $matches) === 1) {
            $decoded = json_decode($matches[1], true);

            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }
        }

        return [];
    }

    private function prompt(array $events): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的 Groq 前處理層。',
            '任務：把已由程式聚合過的事件群整理成事件摘要、情緒方向、題材分類、產業分類。',
            '限制：不要預測價格，不要給買賣建議。',
            '請輸出 JSON array，每筆包含 cluster_id, summary_zh, sentiment, themes, industries, related_symbols, importance_score。',
            '只輸出 JSON，不要 markdown，不要解釋，不要補充說明。',
            '事件群資料：',
            json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
