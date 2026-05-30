<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BuildAgentKnowledgeBases extends Command
{
    protected $signature = 'market:agents-build-knowledge-bases
        {--date= : Build date, default today in Asia/Taipei}
        {--fetch-news : Fetch free RSS/news sources}
        {--seed-language : Seed larger initial language/template libraries}
        {--limit=120 : Max rows per source}';

    protected $description = 'Build structured agent knowledge bases and seed larger language/template libraries.';

    private string $date;

    private int $limit;

    public function handle(): int
    {
        if (! $this->requiredTablesExist()) {
            $this->error('Structured knowledge tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $this->date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $this->limit = max(20, min(500, (int) $this->option('limit')));

        $news = (bool) $this->option('fetch-news') ? $this->fetchExternalNews() : ['inserted' => 0, 'updated' => 0, 'failed_sources' => []];
        $sync = $this->syncStructuredKnowledge();
        $language = (bool) $this->option('seed-language') ? $this->seedLanguageLibrary() : ['language_assets' => 0, 'paragraph_templates' => 0, 'article_templates' => 0];

        $this->info('Structured knowledge bases built.');
        $this->line('News inserted/updated: '.$news['inserted'].'/'.$news['updated']);
        $this->line('Structured sync: '.json_encode($sync, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Language seeds: '.json_encode($language, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($news['failed_sources'] !== []) {
            $this->warn('Failed news sources: '.implode(', ', $news['failed_sources']));
        }

        return self::SUCCESS;
    }

    private function requiredTablesExist(): bool
    {
        foreach (['news_items', 'market_events', 'theme_knowledge', 'industry_knowledge', 'historical_cases', 'language_assets', 'paragraph_templates', 'article_templates'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{inserted:int,updated:int,failed_sources:array<int,string>}
     */
    private function fetchExternalNews(): array
    {
        $sources = [
            [
                'name' => 'Google News 台股',
                'url' => 'https://news.google.com/rss/search?q=%E5%8F%B0%E8%82%A1%20when%3A1d&hl=zh-TW&gl=TW&ceid=TW:zh-Hant',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'taiwan_market',
            ],
            [
                'name' => 'Google News 半導體',
                'url' => 'https://news.google.com/rss/search?q=%E5%8D%8A%E5%B0%8E%E9%AB%94%20AI%20when%3A1d&hl=zh-TW&gl=TW&ceid=TW:zh-Hant',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'technology',
            ],
            [
                'name' => 'Yahoo Finance',
                'url' => 'https://finance.yahoo.com/news/rssindex',
                'language' => 'en',
                'region' => 'Global',
                'category' => 'global_market',
            ],
            [
                'name' => 'CNBC Markets',
                'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html',
                'language' => 'en',
                'region' => 'US',
                'category' => 'global_market',
            ],
            [
                'name' => '經濟日報',
                'url' => 'https://money.udn.com/rssfeed/news/1001',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'taiwan_market',
                'source_type' => 'rss',
            ],
            [
                'name' => '工商時報即時新聞',
                'url' => 'https://www.ctee.com.tw/livenews',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'taiwan_market',
                'source_type' => 'html_list',
                'parser' => 'html_links',
                'match_url_contains' => ['/news/'],
            ],
            [
                'name' => '鉅亨網台股新聞',
                'url' => 'https://news.cnyes.com/news/cat/tw_stock',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'taiwan_market',
                'source_type' => 'html_list',
                'parser' => 'html_links',
                'match_url_contains' => ['/news/id/'],
            ],
            [
                'name' => '鉅亨網頭條新聞',
                'url' => 'https://news.cnyes.com/news/cat/headline',
                'language' => 'zh-TW',
                'region' => 'Global',
                'category' => 'global_market',
                'source_type' => 'html_list',
                'parser' => 'html_links',
                'match_url_contains' => ['/news/id/'],
            ],
            [
                'name' => '公開資訊觀測站即時重大訊息',
                'url' => 'https://mopsov.twse.com.tw/mops/web/ajax_t05sr01_1',
                'language' => 'zh-TW',
                'region' => 'Taiwan',
                'category' => 'company_disclosure',
                'source_type' => 'mops_html',
                'parser' => 'mops_material_info',
            ],
        ];

        $inserted = 0;
        $updated = 0;
        $failed = [];

        foreach ([$this->fetchFinnhubNews(), $this->fetchMarketauxNews()] as $apiNews) {
            $inserted += $apiNews['inserted'];
            $updated += $apiNews['updated'];
            $failed = array_merge($failed, $apiNews['failed_sources']);
        }

        foreach ($sources as $source) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'MarketX-Agent/1.0'])
                    ->get($source['url']);

                if (! $response->successful()) {
                    $failed[] = $source['name'].' HTTP '.$response->status();
                    continue;
                }

                $items = match ($source['parser'] ?? 'rss') {
                    'html_links' => $this->parseHtmlLinks($response->body(), $source),
                    'mops_material_info' => $this->parseMopsMaterialInfo($response->body(), $source),
                    default => $this->parseRss($response->body()),
                };
                foreach (array_slice($items, 0, $this->limit) as $item) {
                    [$didInsert, $didUpdate] = $this->upsertNewsItem($source, $item);
                    $inserted += $didInsert;
                    $updated += $didUpdate;
                }
            } catch (\Throwable $exception) {
                $failed[] = $source['name'].' '.$exception->getMessage();
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'failed_sources' => $failed];
    }

    /**
     * @return array{inserted:int,updated:int,failed_sources:array<int,string>}
     */
    private function fetchFinnhubNews(): array
    {
        $token = trim((string) config('services.marketx.finnhub_api_key'));
        if ($token === '') {
            return ['inserted' => 0, 'updated' => 0, 'failed_sources' => []];
        }

        $inserted = 0;
        $updated = 0;
        $failed = [];
        $categories = ['general', 'forex'];

        foreach ($categories as $category) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'MarketX-Agent/1.0'])
                    ->get('https://finnhub.io/api/v1/news', [
                        'category' => $category,
                        'token' => $token,
                    ]);

                if (! $response->successful()) {
                    $failed[] = 'Finnhub '.$category.' HTTP '.$response->status();
                    continue;
                }

                $items = $response->json();
                if (! is_array($items)) {
                    $failed[] = 'Finnhub '.$category.' invalid payload';
                    continue;
                }

                $source = [
                    'name' => 'Finnhub '.$category,
                    'url' => 'https://finnhub.io',
                    'language' => 'en',
                    'region' => 'Global',
                    'category' => $category === 'forex' ? 'currency' : 'global_market',
                    'source_type' => 'finnhub_api',
                ];

                foreach (array_slice($items, 0, min($this->limit, 40)) as $item) {
                    if (! is_array($item) || trim((string) ($item['headline'] ?? '')) === '') {
                        continue;
                    }

                    $publishedAt = isset($item['datetime'])
                        ? CarbonImmutable::createFromTimestamp((int) $item['datetime'], 'UTC')->toIso8601String()
                        : '';

                    [$didInsert, $didUpdate] = $this->upsertNewsItem($source, [
                        'title' => (string) ($item['headline'] ?? ''),
                        'summary' => (string) ($item['summary'] ?? ''),
                        'url' => (string) ($item['url'] ?? ('https://finnhub.io/news/'.$category.'/'.($item['id'] ?? sha1((string) ($item['headline'] ?? ''))))),
                        'published_at' => $publishedAt,
                    ]);
                    $inserted += $didInsert;
                    $updated += $didUpdate;
                }
            } catch (\Throwable $exception) {
                $failed[] = 'Finnhub '.$category.' '.$exception->getMessage();
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'failed_sources' => $failed];
    }

    /**
     * @return array{inserted:int,updated:int,failed_sources:array<int,string>}
     */
    private function fetchMarketauxNews(): array
    {
        $token = trim((string) config('services.marketx.marketaux_api_key'));
        if ($token === '') {
            return ['inserted' => 0, 'updated' => 0, 'failed_sources' => []];
        }

        $inserted = 0;
        $updated = 0;
        $failed = [];
        $requests = [
            [
                'name' => 'Marketaux ADR / AI',
                'params' => [
                    'symbols' => 'TSM,UMC,NVDA,AAPL,MSFT,MU,AMD,AVGO',
                    'language' => 'en',
                    'limit' => min(25, $this->limit),
                    'filter_entities' => 'true',
                ],
                'category' => 'global_market',
            ],
            [
                'name' => 'Marketaux Taiwan supply chain',
                'params' => [
                    'search' => 'Taiwan semiconductor OR AI server OR memory OR shipping OR oil OR gold',
                    'language' => 'en',
                    'limit' => min(25, $this->limit),
                    'filter_entities' => 'true',
                ],
                'category' => 'technology',
            ],
        ];

        foreach ($requests as $request) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'MarketX-Agent/1.0'])
                    ->get('https://api.marketaux.com/v1/news/all', $request['params'] + [
                        'api_token' => $token,
                    ]);

                if (! $response->successful()) {
                    $failed[] = $request['name'].' HTTP '.$response->status();
                    continue;
                }

                $items = $response->json('data');
                if (! is_array($items)) {
                    $failed[] = $request['name'].' invalid payload';
                    continue;
                }

                $source = [
                    'name' => $request['name'],
                    'url' => 'https://www.marketaux.com',
                    'language' => 'en',
                    'region' => 'Global',
                    'category' => $request['category'],
                    'source_type' => 'marketaux_api',
                ];

                foreach ($items as $item) {
                    if (! is_array($item) || trim((string) ($item['title'] ?? '')) === '') {
                        continue;
                    }

                    $summary = (string) ($item['description'] ?? $item['snippet'] ?? '');
                    $sourceName = $item['source'] ?? null;
                    if (is_array($sourceName)) {
                        $sourceName = $sourceName['name'] ?? $request['name'];
                    }

                    [$didInsert, $didUpdate] = $this->upsertNewsItem(array_merge($source, [
                        'name' => 'Marketaux '.((string) $sourceName ?: $request['name']),
                    ]), [
                        'title' => (string) ($item['title'] ?? ''),
                        'summary' => $summary,
                        'url' => (string) ($item['url'] ?? ('https://www.marketaux.com/news/'.($item['uuid'] ?? sha1((string) ($item['title'] ?? ''))))),
                        'published_at' => (string) ($item['published_at'] ?? ''),
                    ]);
                    $inserted += $didInsert;
                    $updated += $didUpdate;
                }
            } catch (\Throwable $exception) {
                $failed[] = $request['name'].' '.$exception->getMessage();
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'failed_sources' => $failed];
    }

    /**
     * @return array<int,array<string,string|null>>
     */
    private function parseRss(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $feed) {
            return [];
        }

        $nodes = $feed->channel->item ?? $feed->entry ?? [];
        $items = [];

        foreach ($nodes as $node) {
            $link = (string) ($node->link['href'] ?? $node->link ?? '');
            $title = trim((string) ($node->title ?? ''));

            if ($title === '') {
                continue;
            }

            $items[] = [
                'title' => html_entity_decode($title, ENT_QUOTES | ENT_HTML5),
                'summary' => trim(strip_tags(html_entity_decode((string) ($node->description ?? $node->summary ?? ''), ENT_QUOTES | ENT_HTML5))),
                'url' => $link,
                'published_at' => (string) ($node->pubDate ?? $node->published ?? $node->updated ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<int,array<string,string|null>>
     */
    private function parseHtmlLinks(string $html, array $source): array
    {
        $items = [];
        $seen = [];
        preg_match_all('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $title = $this->cleanHtmlText($match[3] ?? '');
            $href = html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5);

            if (mb_strlen($title) < 8 || $href === '' || Str::startsWith($href, ['#', 'javascript:'])) {
                continue;
            }

            $url = $this->absoluteUrl((string) $source['url'], $href);
            $filters = $source['match_url_contains'] ?? [];
            if (is_array($filters) && $filters !== [] && ! collect($filters)->contains(fn ($needle) => Str::contains($url, (string) $needle))) {
                continue;
            }

            $key = hash('sha256', $url.'|'.$title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'title' => $title,
                'summary' => '',
                'url' => $url,
                'published_at' => '',
            ];

            if (count($items) >= $this->limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<int,array<string,string|null>>
     */
    private function parseMopsMaterialInfo(string $html, array $source): array
    {
        $items = [];
        $seen = [];
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $rows);

        foreach ($rows[1] ?? [] as $rowHtml) {
            preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches);
            $cells = collect($cellMatches[1] ?? [])
                ->map(fn ($cell) => $this->cleanHtmlText((string) $cell))
                ->filter(fn ($cell) => $cell !== '')
                ->values()
                ->all();

            if (count($cells) < 3) {
                continue;
            }

            $title = collect($cells)
                ->filter(fn ($cell) => Str::contains($cell, ['公告', '董事會', '代子公司', '本公司', '說明']))
                ->last() ?: end($cells);

            $title = trim((string) $title);
            if (mb_strlen($title) < 8) {
                continue;
            }

            $prefix = trim(implode(' ', array_slice($cells, 0, min(4, count($cells)))));
            $fullTitle = trim($prefix.' '.$title);
            $key = hash('sha256', $fullTitle);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'title' => $fullTitle,
                'summary' => '公開資訊觀測站即時重大訊息',
                'url' => (string) $source['url'].'#'.$key,
                'published_at' => '',
            ];

            if (count($items) >= $this->limit) {
                break;
            }
        }

        if ($items === []) {
            return $this->parseHtmlLinks($html, $source + ['match_url_contains' => ['/mops/web/']]);
        }

        return $items;
    }

    private function cleanHtmlText(string $html): string
    {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function absoluteUrl(string $baseUrl, string $href): string
    {
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (Str::startsWith($href, '//')) {
            return $scheme.':'.$href;
        }

        if (Str::startsWith($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.($dir === '' ? '' : $dir).'/'.$href;
    }

    /**
     * @param array<string,string> $source
     * @param array<string,string|null> $item
     * @return array{0:int,1:int}
     */
    private function upsertNewsItem(array $source, array $item): array
    {
        $url = $item['url'] ?: $source['url'].'#'.sha1((string) $item['title']);
        $hash = hash('sha256', $url);
        $publishedAt = $item['published_at'] ? $this->parseTime($item['published_at']) : now('Asia/Taipei');
        $text = trim($item['title'].' '.$item['summary']);
        $keywords = $this->extractKeywords($text);
        $themes = $this->matchThemeNames($text);
        $industries = $this->inferIndustries($text, $themes);

        $payload = [
            'source_name' => $source['name'],
            'source_type' => $source['source_type'] ?? 'rss',
            'url' => $url,
            'published_at' => $publishedAt,
            'news_date' => $publishedAt?->timezone('Asia/Taipei')->toDateString() ?? $this->date,
            'title' => $item['title'],
            'summary' => Str::limit((string) $item['summary'], 1200, ''),
            'content' => null,
            'language' => $source['language'],
            'region' => $source['region'],
            'category' => $source['category'],
            'sentiment' => $this->inferSentiment($text),
            'importance_score' => $this->importanceFromText($text, $keywords),
            'themes' => $this->json($themes),
            'industries' => $this->json($industries),
            'symbols' => $this->json([]),
            'keywords' => $this->json($keywords),
            'raw_payload' => $this->json(['source' => $source, 'item' => $item]),
            'status' => 'active',
            'updated_at' => now(),
        ];

        $existing = DB::table('news_items')->where('url_hash', $hash)->first();
        if ($existing) {
            DB::table('news_items')->where('id', $existing->id)->update($payload);
            $this->syncNewsToKnowledge((int) $existing->id, $payload + ['url_hash' => $hash]);

            return [0, 1];
        }

        $id = DB::table('news_items')->insertGetId($payload + [
            'url_hash' => $hash,
            'created_at' => now(),
        ]);
        $this->syncNewsToKnowledge((int) $id, $payload + ['url_hash' => $hash]);

        return [1, 0];
    }

    /**
     * @param array<string,mixed> $news
     */
    private function syncNewsToKnowledge(int $newsId, array $news): void
    {
        DB::table('market_knowledge_items')->updateOrInsert(
            [
                'knowledge_type' => 'news',
                'source_type' => 'news_items',
                'source_id' => (string) $newsId,
            ],
            [
                'source_name' => $news['source_name'],
                'source_url' => $news['url'],
                'knowledge_date' => $news['news_date'],
                'occurred_at' => $news['published_at'],
                'title' => $news['title'],
                'summary' => $news['summary'],
                'body' => $news['content'],
                'category' => $news['category'],
                'region' => $news['region'],
                'sentiment' => $news['sentiment'],
                'importance_score' => $news['importance_score'],
                'confidence_score' => 68,
                'themes' => $news['themes'],
                'industries' => $news['industries'],
                'symbols' => $news['symbols'],
                'keywords' => $news['keywords'],
                'evidence_payload' => $news['raw_payload'],
                'status' => 'active',
                'expires_at' => now()->addDays(14),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @return array<string,int>
     */
    private function syncStructuredKnowledge(): array
    {
        $counts = [
            'news_items' => 0,
            'market_events' => 0,
            'theme_knowledge' => 0,
            'industry_knowledge' => 0,
            'historical_cases' => 0,
        ];

        $counts['news_items'] += $this->syncKnowledgeNews();
        $counts['market_events'] += $this->syncMarketEvents();
        $counts['theme_knowledge'] += $this->syncThemeKnowledge();
        $counts['industry_knowledge'] += $this->syncIndustryKnowledge();
        $counts['historical_cases'] += $this->syncHistoricalCases();

        return $counts;
    }

    private function syncKnowledgeNews(): int
    {
        $rows = DB::table('market_knowledge_items')
            ->where('knowledge_type', 'news')
            ->orderByDesc('knowledge_date')
            ->limit($this->limit)
            ->get();
        $count = 0;

        foreach ($rows as $row) {
            $url = $row->source_url ?: 'market_knowledge_items:'.$row->id;
            DB::table('news_items')->updateOrInsert(
                ['url_hash' => hash('sha256', $url)],
                [
                    'source_name' => $row->source_name,
                    'source_type' => $row->source_type ?: 'marketx',
                    'url' => $row->source_url,
                    'published_at' => $row->occurred_at,
                    'news_date' => $row->knowledge_date,
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'content' => $row->body,
                    'language' => 'zh-TW',
                    'region' => $row->region,
                    'category' => $row->category,
                    'sentiment' => $row->sentiment,
                    'importance_score' => $row->importance_score,
                    'themes' => $row->themes,
                    'industries' => $row->industries,
                    'symbols' => $row->symbols,
                    'keywords' => $row->keywords,
                    'raw_payload' => $row->evidence_payload,
                    'status' => $row->status,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function syncMarketEvents(): int
    {
        $clusters = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit($this->limit)
            ->get();
        $count = 0;

        foreach ($clusters as $cluster) {
            DB::table('market_events')->updateOrInsert(
                ['event_key' => 'cluster:'.$cluster->id],
                [
                    'event_date' => $cluster->cluster_date,
                    'title' => $cluster->title,
                    'summary' => $cluster->summary,
                    'category' => $cluster->category,
                    'region' => $cluster->region,
                    'sentiment' => $cluster->sentiment,
                    'importance_score' => $cluster->importance_score,
                    'themes' => $cluster->themes,
                    'industries' => $cluster->industries,
                    'symbols' => $cluster->related_symbols,
                    'source_news_ids' => $cluster->event_ids,
                    'raw_payload' => $cluster->ai_payload,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function syncThemeKnowledge(): int
    {
        $themes = DB::table('themes')
            ->leftJoin('theme_scores', function ($join) {
                $join->on('themes.id', '=', 'theme_scores.theme_id')
                    ->whereRaw('theme_scores.score_date = (select max(ts.score_date) from theme_scores ts where ts.theme_id = themes.id)');
            })
            ->where('themes.is_active', true)
            ->limit($this->limit)
            ->get([
                'themes.id',
                'themes.name',
                'themes.slug',
                'themes.description',
                'theme_scores.score_date',
                'theme_scores.heat_score',
                'theme_scores.news_score',
                'theme_scores.price_score',
                'theme_scores.volume_score',
                'theme_scores.payload',
            ]);
        $count = 0;

        foreach ($themes as $theme) {
            $symbols = DB::table('stock_theme_map')
                ->join('stocks', 'stocks.id', '=', 'stock_theme_map.stock_id')
                ->where('stock_theme_map.theme_id', $theme->id)
                ->orderByDesc('stock_theme_map.weight')
                ->limit(12)
                ->get(['stocks.symbol', 'stocks.name'])
                ->map(fn ($row) => ['symbol' => $row->symbol, 'name' => $row->name])
                ->values()
                ->all();

            DB::table('theme_knowledge')->updateOrInsert(
                ['theme_name' => $theme->name],
                [
                    'theme_id' => $theme->id,
                    'theme_slug' => $theme->slug,
                    'definition' => $theme->description ?: $theme->name.'題材代表市場正在追蹤的產業或資金焦點。',
                    'bullish_drivers' => '新聞熱度增加、代表股轉強、量價同步、法人買盤延續。',
                    'risk_drivers' => '題材過熱、僅少數個股上漲、法人轉賣、股價與營收落差擴大。',
                    'keywords' => $this->json([$theme->name, $theme->slug]),
                    'representative_symbols' => $this->json($symbols),
                    'latest_metrics' => $this->json([
                        'heat_score' => $theme->heat_score,
                        'news_score' => $theme->news_score,
                        'price_score' => $theme->price_score,
                        'volume_score' => $theme->volume_score,
                        'payload' => $this->decodeJson($theme->payload),
                    ]),
                    'asof_date' => $theme->score_date,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function syncIndustryKnowledge(): int
    {
        $industries = DB::table('stocks')
            ->where('is_active', true)
            ->whereNotNull('industry')
            ->groupBy('industry')
            ->orderBy('industry')
            ->limit($this->limit)
            ->pluck('industry');
        $count = 0;

        foreach ($industries as $industry) {
            $symbols = DB::table('stocks')
                ->where('is_active', true)
                ->where('industry', $industry)
                ->orderBy('symbol')
                ->limit(16)
                ->get(['symbol', 'name'])
                ->map(fn ($row) => ['symbol' => $row->symbol, 'name' => $row->name])
                ->values()
                ->all();

            DB::table('industry_knowledge')->updateOrInsert(
                ['industry_name' => $industry],
                [
                    'definition' => $industry.'是台股產業分類之一，需搭配代表股走勢、營收、籌碼與題材熱度觀察。',
                    'supply_chain_notes' => '先看產業內代表股是否同步，再看資金是否擴散到中小型個股。',
                    'bullish_drivers' => '報價上漲、訂單能見度提升、代表股帶量突破、法人買盤延續。',
                    'risk_drivers' => '需求放緩、庫存升高、漲多修正、題材未擴散、籌碼轉弱。',
                    'representative_symbols' => $this->json($symbols),
                    'related_themes' => $this->json([]),
                    'latest_metrics' => $this->json(['stock_count' => count($symbols)]),
                    'asof_date' => $this->date,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function syncHistoricalCases(): int
    {
        $rows = DB::table('stock_radar_observations as o')
            ->leftJoin('stock_radar_observation_checks as c', function ($join) {
                $join->on('c.stock_radar_observation_id', '=', 'o.id')
                    ->whereIn('c.days_since_selected', [1, 3, 5]);
            })
            ->join('stocks as s', 's.id', '=', 'o.stock_id')
            ->selectRaw('o.id, o.selected_date, o.card_type, o.entry_confidence, o.entry_reasons::text as entry_reasons_text, s.symbol, s.name, avg(c.change_pct) as avg_change_pct, max(c.change_pct) as max_change_pct, min(c.change_pct) as min_change_pct')
            ->groupBy('o.id', 'o.selected_date', 'o.card_type', 'o.entry_confidence', DB::raw('o.entry_reasons::text'), 's.symbol', 's.name')
            ->orderByDesc('o.selected_date')
            ->limit($this->limit)
            ->get();
        $count = 0;

        foreach ($rows as $row) {
            DB::table('historical_cases')->updateOrInsert(
                ['case_key' => 'radar:'.$row->id],
                [
                    'case_type' => 'radar_card_validation',
                    'title' => $row->name.' '.$row->symbol.'｜'.$row->card_type.' 後續驗證',
                    'background' => '系統在 '.$row->selected_date.' 將此股放入 '.$row->card_type.' 卡片，當時信心指數 '.$row->entry_confidence.'%。',
                    'trigger_event' => '入選原因：'.$this->reasonText($row->entry_reasons_text),
                    'market_reaction' => '後續平均漲跌幅 '.($row->avg_change_pct === null ? '尚無資料' : number_format((float) $row->avg_change_pct, 2).'%').'。',
                    'lesson' => '若同類條件反覆失準，代理人應建議調整卡片分類規則或權重。',
                    'case_date' => $row->selected_date,
                    'themes' => $this->json([]),
                    'industries' => $this->json([]),
                    'symbols' => $this->json([['symbol' => $row->symbol, 'name' => $row->name]]),
                    'metrics' => $this->json([
                        'avg_change_pct' => $row->avg_change_pct,
                        'max_change_pct' => $row->max_change_pct,
                        'min_change_pct' => $row->min_change_pct,
                    ]),
                    'confidence_score' => 72,
                    'source' => 'radar_observation',
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,int>
     */
    private function seedLanguageLibrary(): array
    {
        $assets = $this->languageAssets();
        $paragraphs = $this->paragraphTemplates();
        $articles = $this->articleTemplates();

        $assetCount = 0;
        foreach ($assets as $asset) {
            DB::table('language_assets')->updateOrInsert(
                [
                    'asset_type' => $asset['asset_type'],
                    'section' => $asset['section'],
                    'condition_key' => $asset['condition_key'],
                    'text' => $asset['text'],
                ],
                [
                    'tone' => $asset['tone'],
                    'weight' => $asset['weight'],
                    'source' => 'manual_seed',
                    'status' => 'active',
                    'metadata' => $this->json(['seed_version' => 'v2']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $assetCount++;
        }

        $paragraphCount = 0;
        foreach ($paragraphs as $paragraph) {
            DB::table('paragraph_templates')->updateOrInsert(
                ['template_key' => $paragraph['template_key']],
                [
                    'name' => $paragraph['name'],
                    'section' => $paragraph['section'],
                    'scenario' => $paragraph['scenario'],
                    'tone' => $paragraph['tone'],
                    'body_template' => $paragraph['body_template'],
                    'required_conditions' => $this->json($paragraph['required_conditions']),
                    'optional_conditions' => $this->json($paragraph['optional_conditions']),
                    'weight' => $paragraph['weight'],
                    'source' => 'manual_seed',
                    'status' => 'active',
                    'metadata' => $this->json(['seed_version' => 'v2']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $paragraphCount++;
        }

        $articleCount = 0;
        foreach ($articles as $article) {
            DB::table('article_templates')->updateOrInsert(
                ['template_key' => $article['template_key']],
                [
                    'name' => $article['name'],
                    'scenario' => $article['scenario'],
                    'tone' => $article['tone'],
                    'section_order' => $this->json($article['section_order']),
                    'opening_template' => $article['opening_template'],
                    'closing_template' => $article['closing_template'],
                    'style_rules' => $this->json($article['style_rules']),
                    'selection_rules' => $this->json($article['selection_rules']),
                    'weight' => $article['weight'],
                    'source' => 'manual_seed',
                    'status' => 'active',
                    'metadata' => $this->json(['seed_version' => 'v2']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $articleCount++;
        }

        return [
            'language_assets' => $assetCount,
            'paragraph_templates' => $paragraphCount,
            'article_templates' => $articleCount,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function languageAssets(): array
    {
        return [
            ['asset_type' => 'connector', 'section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'contrast', 'text' => '不過，這個判斷仍要回到量能、法人與營收是否延續來確認。', 'weight' => 70],
            ['asset_type' => 'connector', 'section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'follow_up', 'text' => '接下來的關鍵不是單日漲跌，而是原本支撐股價的條件是否繼續存在。', 'weight' => 72],
            ['asset_type' => 'phrase', 'section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'base_rebound', 'text' => '股價先經過整理，今日放量轉強，代表買盤開始重新測試上方壓力。', 'weight' => 80],
            ['asset_type' => 'phrase', 'section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'trend_continuation', 'text' => '近期股價沿短均線墊高，若量能沒有失控放大，通常代表趨勢仍由買方掌握。', 'weight' => 78],
            ['asset_type' => 'phrase', 'section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'overextended', 'text' => '短線漲幅已經拉開，若後續題材或營收沒有新的支撐，容易出現獲利了結賣壓。', 'weight' => 76],
            ['asset_type' => 'phrase', 'section' => 'price_theme', 'tone' => 'bear', 'condition_key' => 'weak_rebound', 'text' => '反彈力道仍偏弱，若量能無法放大，較像跌深後的技術性修正。', 'weight' => 74],
            ['asset_type' => 'phrase', 'section' => 'technical', 'tone' => 'bull', 'condition_key' => 'macd_turning', 'text' => 'MACD 柱狀體由弱轉強，代表短線動能正在改善。', 'weight' => 75],
            ['asset_type' => 'phrase', 'section' => 'technical', 'tone' => 'risk', 'condition_key' => 'upper_shadow', 'text' => 'K 線留下較長上影線，表示盤中追價買盤遇到明顯賣壓。', 'weight' => 77],
            ['asset_type' => 'phrase', 'section' => 'technical', 'tone' => 'bear', 'condition_key' => 'below_ma', 'text' => '股價仍在主要均線下方，趨勢尚未重新站回多方結構。', 'weight' => 78],
            ['asset_type' => 'phrase', 'section' => 'chip', 'tone' => 'bull', 'condition_key' => 'institutional_buy', 'text' => '法人買盤若能連續出現，通常比單日買超更有參考價值。', 'weight' => 76],
            ['asset_type' => 'phrase', 'section' => 'chip', 'tone' => 'risk', 'condition_key' => 'margin_pressure', 'text' => '融資快速增加會提高籌碼浮動性，股價一旦轉弱容易放大賣壓。', 'weight' => 78],
            ['asset_type' => 'phrase', 'section' => 'fundamental', 'tone' => 'bull', 'condition_key' => 'revenue_growth', 'text' => '營收年增與月增同步改善時，股價較容易獲得基本面支撐。', 'weight' => 77],
            ['asset_type' => 'phrase', 'section' => 'fundamental', 'tone' => 'risk', 'condition_key' => 'valuation_gap', 'text' => '若股價漲幅明顯領先營收，後續就需要更強的財報證明。', 'weight' => 79],
            ['asset_type' => 'sentence', 'section' => 'summary', 'tone' => 'bull', 'condition_key' => 'balanced_bull', 'text' => '目前較有利的地方是技術、籌碼與題材至少有兩項同向。', 'weight' => 73],
            ['asset_type' => 'sentence', 'section' => 'summary', 'tone' => 'risk', 'condition_key' => 'balanced_risk', 'text' => '目前不是不能觀察，而是追價前要先確認風險是否已經被市場低估。', 'weight' => 73],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function paragraphTemplates(): array
    {
        return [
            [
                'template_key' => 'price_theme_rebound_v2',
                'name' => '股價整理後放量轉強',
                'section' => 'price_theme',
                'scenario' => 'base_rebound',
                'tone' => 'bull',
                'body_template' => '{stock_name}近期先經過整理，今日股價轉強且量能放大，代表買盤開始重新測試上方壓力。若同時搭配{theme_text}升溫，這種走勢比較像資金重新回到題材，而不是單純跌深反彈。',
                'required_conditions' => ['base_rebound'],
                'optional_conditions' => ['theme_hot_price_up', 'volume_expand'],
                'weight' => 86,
            ],
            [
                'template_key' => 'price_theme_overheat_v2',
                'name' => '題材強但短線漲幅過大',
                'section' => 'price_theme',
                'scenario' => 'risk',
                'tone' => 'risk',
                'body_template' => '{stock_name}仍有{theme_text}支撐，但股價短線已經先反映一段期待。若後續營收、報價或訂單消息沒有新的證據，市場容易從追題材轉成檢查估值。',
                'required_conditions' => ['price_extended'],
                'optional_conditions' => ['valuation_gap', 'upper_shadow'],
                'weight' => 84,
            ],
            [
                'template_key' => 'technical_trend_v2',
                'name' => '均線與動能確認',
                'section' => 'technical',
                'scenario' => 'trend',
                'tone' => 'bull',
                'body_template' => '技術面要看兩件事：第一是股價是否站穩短中期均線，第二是 MACD、KD、RSI 是否同步轉強。若只有價格上漲但動能沒有跟上，就要降低追價判斷。',
                'required_conditions' => ['ma_bull'],
                'optional_conditions' => ['macd_turning', 'kd_golden', 'rsi_strong'],
                'weight' => 78,
            ],
            [
                'template_key' => 'technical_failed_breakout_v2',
                'name' => '突破失敗或上影線',
                'section' => 'technical',
                'scenario' => 'risk',
                'tone' => 'risk',
                'body_template' => '如果盤中拉高後收不住，或突破後很快跌回壓力區下方，代表上方賣壓仍在。這類型股票不是只看有沒有漲，而是要看收盤能不能站穩。',
                'required_conditions' => ['upper_shadow'],
                'optional_conditions' => ['volume_expand', 'macd_shrinking'],
                'weight' => 80,
            ],
            [
                'template_key' => 'chip_institutional_v2',
                'name' => '法人買盤延續',
                'section' => 'chip',
                'scenario' => 'institutional_buy',
                'tone' => 'bull',
                'body_template' => '籌碼面如果看到外資、投信或主力買盤延續，代表資金不是只做一日行情。若買盤集中在代表股，後續還要觀察是否擴散到同題材其他個股。',
                'required_conditions' => ['institutional_buy'],
                'optional_conditions' => ['foreign_trust_buy'],
                'weight' => 78,
            ],
            [
                'template_key' => 'chip_margin_risk_v2',
                'name' => '融資與籌碼浮動風險',
                'section' => 'chip',
                'scenario' => 'margin_risk',
                'tone' => 'risk',
                'body_template' => '籌碼風險主要看融資是否快速增加，以及法人是否開始轉賣。若股價已漲高但籌碼越來越浮動，拉回時通常會比基本面變化更快反映。',
                'required_conditions' => ['margin_pressure'],
                'optional_conditions' => ['institutional_sell'],
                'weight' => 80,
            ],
            [
                'template_key' => 'fundamental_growth_v2',
                'name' => '營收財報支撐股價',
                'section' => 'fundamental',
                'scenario' => 'growth',
                'tone' => 'bull',
                'body_template' => '基本面重點是營收成長是否能支撐股價。如果月營收、年增率與毛利率方向一致，股價上漲就比較有基本面依據。',
                'required_conditions' => ['revenue_growth'],
                'optional_conditions' => ['profit_quality_good'],
                'weight' => 77,
            ],
            [
                'template_key' => 'summary_card_alignment_v2',
                'name' => '總評與卡片分類一致',
                'section' => 'summary',
                'scenario' => 'card_alignment',
                'tone' => 'neutral',
                'body_template' => '總結來看，這檔股票要先對照它目前被放入的卡片分類。如果是優先觀察，重點是條件能否延續；如果是風險升高，重點不是看空，而是提醒漲幅、籌碼或估值可能已經需要重新檢查。',
                'required_conditions' => ['card_type'],
                'optional_conditions' => ['risk', 'priority', 'potential'],
                'weight' => 82,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function articleTemplates(): array
    {
        return [
            [
                'template_key' => 'stock_priority_observation_v2',
                'name' => '優先觀察股報告',
                'scenario' => 'priority',
                'tone' => 'bull',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '這類股票的重點是確認上漲條件是否同步，而不是只看單日漲幅。',
                'closing_template' => '若題材、量能與法人買盤能延續，觀察理由才算成立；反之就要把它從優先觀察降級。',
                'style_rules' => ['must_match_radar_card_type' => true, 'avoid_buy_sell_words' => true, 'must_reference_recent_price_action' => true],
                'selection_rules' => ['card_type' => 'priority'],
                'weight' => 86,
            ],
            [
                'template_key' => 'stock_risk_observation_v2',
                'name' => '風險升高股報告',
                'scenario' => 'risk',
                'tone' => 'risk',
                'section_order' => ['price_theme', 'fundamental', 'chip', 'technical', 'summary'],
                'opening_template' => '這類股票不是單純看空，而是提醒股價可能已經領先題材或基本面。',
                'closing_template' => '後續要看營收與籌碼能不能跟上，若只剩價格強勢，風險會比機會更需要優先處理。',
                'style_rules' => ['must_explain_risk_source' => true, 'avoid_buy_sell_words' => true, 'must_reference_valuation_or_chip' => true],
                'selection_rules' => ['card_type' => 'risk'],
                'weight' => 88,
            ],
            [
                'template_key' => 'stock_low_volume_breakout_v2',
                'name' => '低檔爆量股報告',
                'scenario' => 'low_volume',
                'tone' => 'neutral',
                'section_order' => ['price_theme', 'technical', 'chip', 'summary'],
                'opening_template' => '低檔爆量的重點是分辨資金回流，還是短線反彈後又轉弱。',
                'closing_template' => '若隔日能守住爆量 K 棒的一半以上，才比較像有效轉強；若很快跌回原區間，就要視為假突破。',
                'style_rules' => ['must_reference_volume' => true, 'must_reference_support' => true],
                'selection_rules' => ['card_type' => 'low_volume'],
                'weight' => 84,
            ],
            [
                'template_key' => 'stock_weak_trend_v2',
                'name' => '持續弱勢股報告',
                'scenario' => 'weak',
                'tone' => 'bear',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '持續弱勢股要先看止跌訊號，不要把單日反彈誤判成趨勢翻多。',
                'closing_template' => '除非重新站回短中期均線並出現量價配合，否則仍應以弱勢整理看待。',
                'style_rules' => ['must_avoid_overheat_words_when_downtrend' => true, 'must_reference_trend' => true],
                'selection_rules' => ['card_type' => 'weak'],
                'weight' => 82,
            ],
            [
                'template_key' => 'stock_potential_watch_v2',
                'name' => '潛力觀察股報告',
                'scenario' => 'potential',
                'tone' => 'neutral',
                'section_order' => ['theme', 'price_theme', 'technical', 'fundamental', 'summary'],
                'opening_template' => '潛力觀察股的重點是條件正在形成，但還沒完全確認。',
                'closing_template' => '若題材熱度、股價結構與籌碼後續同步改善，才會升級為更明確的觀察名單。',
                'style_rules' => ['must_explain_unconfirmed_conditions' => true, 'avoid_buy_sell_words' => true],
                'selection_rules' => ['card_type' => 'potential'],
                'weight' => 80,
            ],
            [
                'template_key' => 'stock_balanced_health_check_v2',
                'name' => '一般個股健檢報告',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '先看股價位置，再看技術、籌碼、財報與題材是否互相支持。',
                'closing_template' => '如果四個面向沒有同向，就應降低判斷強度，避免只用單一指標做結論。',
                'style_rules' => ['avoid_repeated_phrases' => true, 'must_reference_recent_price_action' => true],
                'selection_rules' => ['default' => true],
                'weight' => 70,
            ],
        ];
    }

    private function parseTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->timezone('Asia/Taipei');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function extractKeywords(string $text): array
    {
        $dictionary = [
            'AI', 'AI Server', 'CoWoS', '散熱', '重電', '電力', '記憶體', 'DRAM', 'NAND',
            '銅', '鋼鐵', '航運', '油價', '黃金', '美元', '美債', 'Fed', 'NVIDIA',
            '台積電', 'Apple', 'Microsoft', '地緣政治', '關稅', '匯率', '半導體',
            '美光', 'Micron', '資料中心', '雲端', '機器人',
        ];

        return collect($dictionary)
            ->filter(fn (string $word) => Str::contains(Str::lower($text), Str::lower($word)))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function matchThemeNames(string $text): array
    {
        return DB::table('themes')
            ->where('is_active', true)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->filter(fn (string $theme) => Str::contains(Str::lower($text), Str::lower($theme)))
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $themes
     * @return array<int,string>
     */
    private function inferIndustries(string $text, array $themes): array
    {
        $joined = Str::lower($text.' '.implode(' ', $themes));
        $map = [
            'AI' => 'AI 供應鏈',
            'server' => '雲端與資料中心',
            '記憶體' => '半導體',
            'dram' => '半導體',
            '散熱' => '電子零組件',
            '重電' => '電機機械',
            '銅' => '原物料',
            '鋼鐵' => '原物料',
            '航運' => '航運',
            '油' => '能源',
            '黃金' => '貴金屬',
        ];

        $industries = [];
        foreach ($map as $needle => $industry) {
            if (Str::contains($joined, Str::lower($needle))) {
                $industries[] = $industry;
            }
        }

        return array_values(array_unique($industries));
    }

    /**
     * @param array<int,string> $keywords
     */
    private function importanceFromText(string $text, array $keywords): int
    {
        $score = 40 + min(35, count($keywords) * 7);
        if (Str::contains(Str::lower($text), ['nvidia', 'fed', '台積電', '美光', 'micron', '關稅', '戰爭'])) {
            $score += 15;
        }

        return max(0, min(100, $score));
    }

    private function inferSentiment(string $text): string
    {
        $lower = Str::lower($text);
        $riskWords = ['下跌', '衰退', '風險', '制裁', '戰爭', '通膨', '升息', '禁令', '賣壓', 'plunge', 'falls', 'war'];
        $bullWords = ['上漲', '成長', '需求增加', '降息', '買盤', '報價上漲', '突破', '優於預期', 'rally', 'beats', 'surge'];

        if (collect($riskWords)->contains(fn ($word) => Str::contains($lower, Str::lower($word)))) {
            return 'negative';
        }

        if (collect($bullWords)->contains(fn ($word) => Str::contains($lower, Str::lower($word)))) {
            return 'positive';
        }

        return 'neutral';
    }

    private function reasonText(mixed $value): string
    {
        $decoded = $this->decodeJson($value);

        return collect($decoded)
            ->map(fn ($reason) => is_array($reason) ? ($reason['label'] ?? null) : null)
            ->filter()
            ->implode('、') ?: '未記錄';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
