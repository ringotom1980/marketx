<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;

class GroqProvider
{
    public function configured(): bool
    {
        return filled(config('services.marketx.groq_api_key'));
    }

    public function model(): string
    {
        return (string) config('services.marketx.groq_model', 'llama-3.1-8b-instant');
    }

    public function chat(string $prompt, bool $live = false): AiResult
    {
        $model = $this->model();

        if (! config('services.marketx.ai_pipeline_enabled')) {
            return AiResult::disabled('groq', $model, 'AI pipeline disabled');
        }

        if (! $this->configured()) {
            return AiResult::disabled('groq', $model, 'Groq API key missing');
        }

        if (! $live) {
            return AiResult::disabled('groq', $model, 'Live AI call not requested');
        }

        $response = Http::timeout(45)
            ->retry(1, 1000)
            ->withToken((string) config('services.marketx.groq_api_key'))
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => '你是《股市在幹嘛》的金融資料前處理與研究摘要助理。請使用繁體中文，根據使用者提供的資料回答，不要編造資料，不要提供明確買賣建議。'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->ok()) {
            return new AiResult(false, 'groq', $model, null, $response->body());
        }

        $payload = $response->json();
        $text = data_get($payload, 'choices.0.message.content');

        return new AiResult(true, 'groq', $model, $text, null, data_get($payload, 'usage', []));
    }
}
