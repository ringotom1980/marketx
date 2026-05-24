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
        {--limit=5 : Number of latest events to include}
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

        $events = DB::table('global_events')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'event_date', 'source', 'title', 'summary', 'category', 'region']);

        if ($events->isEmpty()) {
            $this->warn('No global events available.');
            return self::SUCCESS;
        }

        $prompt = $this->prompt($events->toArray());
        $result = $groq->chat($prompt, $live);
        $pipeline->log($task, $result, $prompt);

        if (! $result->ok) {
            $this->warn('Groq preprocessing skipped/failed: '.$result->error);
            return self::SUCCESS;
        }

        DB::table('system_logs')->insert([
            'level' => 'info',
            'source' => 'AI Groq Preprocess',
            'message' => 'Groq event preprocessing completed.',
            'context' => json_encode([
                'event_count' => $events->count(),
                'model' => $result->model,
                'text' => $result->text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Groq event preprocessing completed.');
        $this->line((string) $result->text);

        return self::SUCCESS;
    }

    private function prompt(array $events): string
    {
        return implode("\n", [
            '你是《股市在幹嘛》的 Groq 前處理層。',
            '任務：把新聞整理成事件摘要、情緒方向、題材分類、產業分類。',
            '限制：不要預測價格，不要給買賣建議。',
            '請輸出 JSON array，每筆包含 event_id, summary_zh, sentiment, themes, industries, related_symbols, importance_score。',
            '只輸出 JSON，不要 markdown，不要解釋，不要補充說明。',
            '事件資料：',
            json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
