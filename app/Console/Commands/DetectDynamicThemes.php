<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DetectDynamicThemes extends Command
{
    protected $signature = 'market:detect-dynamic-themes
        {--days=7 : Recent event lookback days}
        {--use-ai : Reserved switch for future AI classification}';

    protected $description = 'Detect dynamic themes from events using rule keywords, with AI payload hooks reserved.';

    public function handle(): int
    {
        $from = CarbonImmutable::now('Asia/Taipei')->subDays(max(1, (int) $this->option('days')));
        $keywords = DB::table('theme_keywords')
            ->join('themes', 'themes.id', '=', 'theme_keywords.theme_id')
            ->where('themes.is_active', true)
            ->get(['theme_keywords.theme_id', 'theme_keywords.keyword', 'theme_keywords.weight']);
        $events = DB::table('global_events')
            ->where('created_at', '>=', $from)
            ->orWhere('event_date', '>=', $from)
            ->orderByDesc('event_date')
            ->limit(300)
            ->get();

        $matches = 0;

        foreach ($events as $event) {
            $text = Str::lower(($event->title ?? '').' '.($event->summary ?? '').' '.($event->category ?? ''));

            foreach ($keywords as $keyword) {
                if ($keyword->keyword === '' || ! str_contains($text, Str::lower($keyword->keyword))) {
                    continue;
                }

                DB::table('theme_event_matches')->updateOrInsert(
                    [
                        'theme_id' => $keyword->theme_id,
                        'global_event_id' => $event->id,
                        'keyword' => $keyword->keyword,
                    ],
                    [
                        'match_score' => max(1, min(100, (int) $keyword->weight)),
                        'source' => 'rule',
                        'ai_prompt' => $this->option('use-ai') ? json_encode([
                            'reserved' => true,
                            'task' => 'classify event to theme',
                            'event_title' => $event->title,
                            'event_summary' => $event->summary,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'ai_response' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $matches++;
            }
        }

        $this->updateThemeAiStatus($this->option('use-ai'));

        $this->info('Dynamic theme event matches upserted: '.$matches);

        return self::SUCCESS;
    }

    private function updateThemeAiStatus(bool $useAi): void
    {
        Theme::query()->where('is_active', true)->update([
            'ai_status' => $useAi ? 'queued' : 'reserved',
            'ai_payload' => [
                'provider' => null,
                'model' => null,
                'mode' => 'reserved_for_future_ai_theme_classification',
                'last_rule_scan_at' => now()->toIso8601String(),
            ],
            'updated_at' => now(),
        ]);
    }
}
