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
        ['source' => 'EIA Today in Energy', 'url' => 'https://www.eia.gov/rss/todayinenergy.xml'],
        ['source' => 'EIA Fuel Update', 'url' => 'https://www.eia.gov/petroleum/gasdiesel/includes/gas_diesel_rss.xml'],
        ['source' => 'Google News - War Risk', 'url' => 'https://news.google.com/rss/search?q=war%20OR%20Ukraine%20OR%20Middle%20East%20OR%20Red%20Sea&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News - Oil', 'url' => 'https://news.google.com/rss/search?q=crude%20oil%20OR%20Brent%20OR%20WTI%20OR%20OPEC&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News - Gold', 'url' => 'https://news.google.com/rss/search?q=gold%20price%20OR%20precious%20metals%20OR%20safe%20haven&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News - Shipping', 'url' => 'https://news.google.com/rss/search?q=container%20freight%20rates%20OR%20shipping%20rates%20OR%20Baltic%20Dry%20Index%20OR%20Red%20Sea%20shipping&hl=en-US&gl=US&ceid=US:en'],
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
            str_contains($lower, 'war') || str_contains($lower, 'ukraine') || str_contains($lower, 'middle east') || str_contains($lower, 'red sea') => 'Geopolitics',
            str_contains($lower, 'oil') || str_contains($lower, 'brent') || str_contains($lower, 'wti') || str_contains($lower, 'opec') || str_contains($lower, 'fuel') => 'Energy',
            str_contains($lower, 'gold') || str_contains($lower, 'precious metal') || str_contains($lower, 'safe haven') => 'Precious Metals',
            str_contains($lower, 'shipping') || str_contains($lower, 'freight') || str_contains($lower, 'container') || str_contains($lower, 'baltic dry') => 'Shipping',
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
