<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportGlobalEvents extends Command
{
    protected $signature = 'market:import-global-events';

    protected $description = 'Import global event/news records from public RSS feeds.';

    private const FEEDS = [
        ['source' => 'Federal Reserve', 'url' => 'https://www.federalreserve.gov/feeds/press_all.xml'],
        ['source' => 'NVIDIA Blog', 'url' => 'https://blogs.nvidia.com/feed/'],
        ['source' => 'Apple Newsroom', 'url' => 'https://www.apple.com/newsroom/rss-feed.rss'],
        ['source' => 'Microsoft Blog', 'url' => 'https://blogs.microsoft.com/feed/'],
    ];

    public function handle(): int
    {
        $inserted = 0;
        $failed = 0;

        foreach (self::FEEDS as $feed) {
            try {
                $inserted += $this->importFeed($feed['source'], $feed['url']);
            } catch (\Throwable $exception) {
                $failed++;
                DB::table('system_logs')->insert([
                    'level' => 'warning',
                    'source' => 'Event Engine',
                    'message' => 'Global event feed failed: '.$feed['source'],
                    'context' => json_encode(['url' => $feed['url'], 'error' => $exception->getMessage()]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info('Global events inserted: '.$inserted);
        $this->line('Failed feeds: '.$failed);

        return self::SUCCESS;
    }

    private function importFeed(string $source, string $url): int
    {
        $response = Http::retry(2, 500)
            ->timeout(25)
            ->withHeaders(['User-Agent' => 'MarketX/1.0'])
            ->get($url);

        if (! $response->ok()) {
            $response->throw();
        }

        $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

        if (! $xml) {
            return 0;
        }

        $items = $xml->channel->item ?? $xml->entry ?? [];
        $count = 0;

        foreach ($items as $item) {
            $title = trim((string) ($item->title ?? ''));

            if ($title === '') {
                continue;
            }

            $publishedAt = $this->publishedAt($item);
            $summary = $this->summary($item);

            $exists = DB::table('global_events')
                ->where('source', $source)
                ->where('title', $title)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('global_events')->insert([
                'event_date' => $publishedAt,
                'source' => $source,
                'title' => $title,
                'summary' => $summary,
                'category' => $this->category($title.' '.$summary),
                'region' => $this->region($source, $title),
                'impact_direction' => null,
                'impact_score' => null,
                'raw_payload' => json_encode(['feed' => $url], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $count++;
        }

        $this->line($source.' events inserted: '.$count);

        return $count;
    }

    private function publishedAt(\SimpleXMLElement $item): ?string
    {
        $value = trim((string) ($item->pubDate ?? $item->published ?? $item->updated ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('Asia/Taipei')->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function summary(\SimpleXMLElement $item): ?string
    {
        $value = trim(strip_tags((string) ($item->description ?? $item->summary ?? '')));

        return $value === '' ? null : mb_substr(preg_replace('/\s+/', ' ', $value), 0, 400);
    }

    private function category(string $text): string
    {
        $lower = mb_strtolower($text);

        return match (true) {
            str_contains($lower, 'fed') || str_contains($lower, 'rate') || str_contains($lower, 'inflation') => 'Fed',
            str_contains($lower, 'ai') || str_contains($lower, 'nvidia') || str_contains($lower, 'gpu') => 'AI',
            str_contains($lower, 'china') || str_contains($lower, 'export') || str_contains($lower, 'geopolitical') => 'Geopolitics',
            str_contains($lower, 'iphone') || str_contains($lower, 'apple') => 'Apple',
            str_contains($lower, 'microsoft') || str_contains($lower, 'azure') => 'Microsoft',
            default => 'Global',
        };
    }

    private function region(string $source, string $title): string
    {
        if ($source === 'Federal Reserve') {
            return 'US';
        }

        return str_contains(mb_strtolower($title), 'china') ? 'China' : 'Global';
    }
}
