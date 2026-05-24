<?php

namespace App\Support\Ai;

class AiReportValidator
{
    public function validateFourPartReport(?string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return ['ok' => false, 'error' => 'empty report'];
        }

        $required = [
            '1｜當前階段判定',
            '2｜關鍵依據',
            '3｜觀察重點',
            '4｜失效條件',
        ];

        foreach ($required as $heading) {
            if (! str_contains($text, $heading)) {
                return ['ok' => false, 'error' => 'missing section: '.$heading];
            }
        }

        return ['ok' => true, 'error' => null];
    }
}
