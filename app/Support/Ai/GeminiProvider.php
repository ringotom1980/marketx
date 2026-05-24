<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Http;

class GeminiProvider
{
    public function configured(): bool
    {
        return filled(config('services.marketx.gemini_api_key'));
    }

    public function model(): string
    {
        return (string) config('services.marketx.gemini_model', 'gemini-1.5-flash');
    }

    public function generate(string $prompt, bool $live = false): AiResult
    {
        $model = $this->model();

        if (! config('services.marketx.ai_pipeline_enabled')) {
            return AiResult::disabled('gemini', $model, 'AI pipeline disabled');
        }

        if (! $this->configured()) {
            return AiResult::disabled('gemini', $model, 'Gemini API key missing');
        }

        if (! $live) {
            return AiResult::disabled('gemini', $model, 'Live AI call not requested');
        }

        $response = Http::timeout(60)
            ->retry(1, 1000)
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent?key='.config('services.marketx.gemini_api_key'),
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'text/plain',
                    ],
                ],
            );

        if (! $response->ok()) {
            return new AiResult(false, 'gemini', $model, null, $response->body());
        }

        $payload = $response->json();
        $text = data_get($payload, 'candidates.0.content.parts.0.text');

        return new AiResult(true, 'gemini', $model, $text, null, data_get($payload, 'usageMetadata', []));
    }
}
