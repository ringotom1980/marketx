<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\DB;

class AiPipelineService
{
    public function __construct(
        private readonly GeminiProvider $gemini,
        private readonly GroqProvider $groq,
        private readonly AiUsageLimiter $limiter,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('services.marketx.ai_pipeline_enabled', false);
    }

    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'gemini_configured' => $this->gemini->configured(),
            'gemini_model' => $this->gemini->model(),
            'groq_configured' => $this->groq->configured(),
            'groq_model' => $this->groq->model(),
            'limits' => [
                'event_preprocess' => [
                    'limit' => $this->limiter->limit('event_preprocess'),
                    'remaining' => $this->limiter->remaining('event_preprocess'),
                ],
                'stock_research' => [
                    'limit' => $this->limiter->limit('stock_research'),
                    'remaining' => $this->limiter->remaining('stock_research'),
                ],
                'dynamic' => [
                    'limit' => $this->limiter->limit('dynamic'),
                    'remaining' => $this->limiter->remaining('dynamic'),
                ],
            ],
        ];
    }

    public function log(string $task, AiResult $result, string $input): void
    {
        DB::table('ai_logs')->insert([
            'task' => $task,
            'model' => $result->provider.':'.$result->model,
            'input_hash' => hash('sha256', $input),
            'prompt_tokens' => data_get($result->usage, 'prompt_tokens') ?? data_get($result->usage, 'promptTokenCount'),
            'completion_tokens' => data_get($result->usage, 'completion_tokens') ?? data_get($result->usage, 'candidatesTokenCount'),
            'cost_estimate' => 0,
            'status' => $result->ok ? 'success_ai' : 'skipped',
            'error_message' => $result->error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
