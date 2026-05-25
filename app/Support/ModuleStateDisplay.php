<?php

namespace App\Support;

class ModuleStateDisplay
{
    public static function fromScore(int|float|null $score, string $module): array
    {
        $score = $score === null ? 0 : (int) $score;

        if ($score <= 0) {
            return ['label' => '資料不足', 'tone' => 'amber'];
        }

        if ($module === '技術結構') {
            return match (true) {
                $score >= 75 => ['label' => '多方結構', 'tone' => 'red'],
                $score >= 60 => ['label' => '偏多整理', 'tone' => 'red'],
                $score >= 45 => ['label' => '震盪觀察', 'tone' => 'amber'],
                default => ['label' => '結構偏弱', 'tone' => 'green'],
            };
        }

        if ($module === '籌碼') {
            return match (true) {
                $score >= 75 => ['label' => '籌碼偏多', 'tone' => 'red'],
                $score >= 60 => ['label' => '資金有撐', 'tone' => 'red'],
                $score >= 45 => ['label' => '籌碼觀察', 'tone' => 'amber'],
                default => ['label' => '籌碼偏弱', 'tone' => 'green'],
            };
        }

        if ($module === '題材熱度') {
            return match (true) {
                $score >= 75 => ['label' => '題材升溫', 'tone' => 'red'],
                $score >= 60 => ['label' => '熱度延續', 'tone' => 'red'],
                $score >= 45 => ['label' => '題材觀察', 'tone' => 'amber'],
                default => ['label' => '熱度不足', 'tone' => 'green'],
            };
        }

        if ($module === '財務營收') {
            return match (true) {
                $score >= 75 => ['label' => '營運穩健', 'tone' => 'red'],
                $score >= 60 => ['label' => '財務尚可', 'tone' => 'red'],
                $score >= 45 => ['label' => '基本面觀察', 'tone' => 'amber'],
                default => ['label' => '基本面偏弱', 'tone' => 'green'],
            };
        }

        return match (true) {
            $score >= 75 => ['label' => '明顯支撐', 'tone' => 'red'],
            $score >= 60 => ['label' => '偏正向', 'tone' => 'red'],
            $score >= 45 => ['label' => '中性觀察', 'tone' => 'amber'],
            default => ['label' => '偏壓抑', 'tone' => 'green'],
        };
    }
}
