<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AiUsageLimiter
{
    public function remaining(string $task): int
    {
        $limit = $this->limit($task);

        return max(0, $limit - $this->usedToday($task));
    }

    public function canRun(string $task): bool
    {
        return $this->remaining($task) > 0;
    }

    public function usedToday(string $task): int
    {
        $today = CarbonImmutable::now('Asia/Taipei')->toDateString();

        return DB::table('ai_logs')
            ->where('task', $task)
            ->whereDate('created_at', $today)
            ->whereIn('status', ['success', 'success_ai'])
            ->count();
    }

    public function limit(string $task): int
    {
        return match ($task) {
            'event_preprocess', 'event_research' => (int) config('services.marketx.max_event_ai_per_day', 20),
            'stock_research' => (int) config('services.marketx.max_stock_ai_per_day', 50),
            'theme_summary' => (int) config('services.marketx.max_theme_ai_per_day', 20),
            'theme_premarket' => (int) config('services.marketx.max_theme_premarket_ai_per_day', 1),
            'global_premarket' => (int) config('services.marketx.max_global_ai_per_day', 1),
            default => (int) config('services.marketx.max_dynamic_ai_per_day', 20),
        };
    }
}
