<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClusterGlobalEvents extends Command
{
    protected $signature = 'market:cluster-global-events
        {--date= : Cluster date, default today in Asia/Taipei}
        {--limit=1000 : Max raw events to cluster}
        {--top=5 : Number of hottest clusters to keep active for homepage}';

    protected $description = 'Cluster today global news by dynamic heat before AI preprocessing.';

    public function handle(): int
    {
        $clusterDate = $this->option('date') ?: CarbonImmutable::now('Asia/Taipei')->toDateString();
        $dayStart = CarbonImmutable::parse($clusterDate, 'Asia/Taipei')->startOfDay();
        $dayEnd = CarbonImmutable::parse($clusterDate, 'Asia/Taipei')->endOfDay();
        $limit = max(50, min(3000, (int) $this->option('limit')));
        $top = max(3, min(10, (int) $this->option('top')));

        $events = $this->events($dayStart, $dayEnd, $limit);

        if ($events->isEmpty()) {
            $this->warn('No events found for '.$clusterDate.'. Falling back to recent 24 hours.');
            $events = $this->events(CarbonImmutable::now('Asia/Taipei')->subDay(), CarbonImmutable::now('Asia/Taipei'), $limit);
        }

        $clusters = $events
            ->groupBy(fn ($event) => $this->clusterKey($event))
            ->map(fn ($group, string $key) => $this->clusterPayload($key, $group->values(), $clusterDate))
            ->sortByDesc('importance_score')
            ->values();

        DB::table('global_event_clusters')->where('cluster_date', $clusterDate)->delete();

        $upserted = 0;

        foreach ($this->diverseTopClusters($clusters, $top) as $cluster) {
            DB::table('global_event_clusters')->insert([
                'cluster_date' => $clusterDate,
                'cluster_key' => $cluster['cluster_key'],
                'title' => $cluster['title'],
                'summary' => $cluster['summary'],
                'category' => $cluster['category'],
                'region' => $cluster['region'],
                'importance_score' => $cluster['importance_score'],
                'sentiment' => $cluster['sentiment'],
                'themes' => json_encode($cluster['themes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'industries' => json_encode($cluster['industries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'related_symbols' => json_encode($cluster['related_symbols'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'event_ids' => json_encode($cluster['event_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ai_status' => 'rule_clustered',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $upserted++;
        }

        $this->info('Raw events considered: '.$events->count());
        $this->info('Candidate clusters: '.$clusters->count());
        $this->info('Hot clusters inserted: '.$upserted);

        return self::SUCCESS;
    }

    private function diverseTopClusters(Collection $clusters, int $top): Collection
    {
        $selected = collect();
        $categoryCounts = [];

        foreach ($clusters as $cluster) {
            $category = $cluster['category'] ?? 'Global';

            if (($categoryCounts[$category] ?? 0) >= 1 && $selected->count() < 3) {
                continue;
            }

            if (($categoryCounts[$category] ?? 0) >= 2) {
                continue;
            }

            $selected->push($cluster);
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            if ($selected->count() >= $top) {
                break;
            }
        }

        return $selected;
    }

    private function events(CarbonImmutable $from, CarbonImmutable $to, int $limit): Collection
    {
        return DB::table('global_events')
            ->whereBetween('event_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'event_date', 'source', 'title', 'summary', 'category', 'region', 'impact_score']);
    }

    private function clusterKey(object $event): string
    {
        $topic = $this->bestTopic($event);

        if ($topic !== null) {
            return $topic;
        }

        $source = Str::slug((string) ($event->source ?? 'global'));
        $words = collect(preg_split('/\W+/u', Str::lower((string) $event->title)))
            ->filter(fn ($word) => mb_strlen($word) >= 4)
            ->take(4)
            ->implode('-');

        return $source.'-'.$words;
    }

    private function clusterPayload(string $key, Collection $events, string $clusterDate): array
    {
        $definition = $this->topicDefinition($key);
        $eventCount = $events->count();
        $sourceCount = $events->pluck('source')->filter()->unique()->count();
        $latestAt = $events->max('event_date');
        $recency = $latestAt ? max(0, 16 - CarbonImmutable::parse($latestAt)->diffInHours(CarbonImmutable::now('Asia/Taipei'))) : 0;
        $baseHeat = 30 + min(35, $eventCount * 5) + min(15, $sourceCount * 5) + min(15, $recency);
        $importance = (int) round(max(20, min(100, $baseHeat + ($definition['heat_bonus'] ?? 0))));

        return [
            'cluster_date' => $clusterDate,
            'cluster_key' => $key,
            'title' => $definition['title'] ?? $this->fallbackTitle($events),
            'summary' => $this->summary($events, $definition),
            'category' => $definition['category'] ?? $events->first()->category ?? 'Global',
            'region' => $definition['region'] ?? $events->first()->region ?? 'Global',
            'importance_score' => $importance,
            'sentiment' => $definition['sentiment'] ?? $this->sentiment($events),
            'themes' => $definition['themes'] ?? [],
            'industries' => $definition['industries'] ?? [],
            'related_symbols' => $definition['symbols'] ?? [],
            'event_ids' => $events->pluck('id')->values()->all(),
        ];
    }

    private function bestTopic(object $event): ?string
    {
        $title = Str::lower((string) ($event->title ?? ''));
        $summary = Str::lower((string) ($event->summary ?? ''));
        $category = Str::lower((string) ($event->category ?? ''));
        $scores = [];

        foreach ($this->topicDefinitions() as $key => $definition) {
            $score = 0;

            foreach ($definition['keywords'] as $keyword) {
                if (str_contains($title, $keyword)) {
                    $score += 5;
                }

                if (str_contains($summary, $keyword)) {
                    $score += 2;
                }

                if ($category !== '' && str_contains($category, $keyword)) {
                    $score += 3;
                }
            }

            if ($score > 0) {
                $scores[$key] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);

        return array_key_first($scores);
    }

    private function topicDefinition(string $key): ?array
    {
        return $this->topicDefinitions()[$key] ?? null;
    }

    private function topicDefinitions(): array
    {
        return [
            'war-geopolitics' => [
                'title' => '戰爭與地緣政治風險升溫',
                'category' => 'Geopolitics',
                'region' => 'Global',
                'sentiment' => 'negative',
                'themes' => ['地緣政治', '能源', '航運運費'],
                'industries' => ['能源', '航運'],
                'symbols' => [],
                'heat_bonus' => 10,
                'keywords' => ['war', 'ukraine', 'russia', 'middle east', 'israel', 'iran', 'red sea', 'geopolitical', 'missile', 'attack', 'shipping rate', 'freight', 'container', 'baltic dry', 'red sea shipping', 'port congestion'],
            ],
            'oil-energy' => [
                'title' => '油價與能源供應成為市場焦點',
                'category' => 'Energy',
                'region' => 'Global',
                'sentiment' => 'neutral',
                'themes' => ['能源'],
                'industries' => ['能源'],
                'symbols' => [],
                'heat_bonus' => 8,
                'keywords' => ['crude oil', 'oil price', 'brent', 'wti', 'opec', 'gasoline', 'fuel', 'energy inventory', 'eia', 'pipeline', 'oil spill', 'oil leak'],
            ],
            'gold-precious-metals' => [
                'title' => '黃金與避險資產買盤受關注',
                'category' => 'Precious Metals',
                'region' => 'Global',
                'sentiment' => 'neutral',
                'themes' => ['貴金屬', '金融與利率'],
                'industries' => ['貴金屬', '金融'],
                'symbols' => [],
                'heat_bonus' => 6,
                'keywords' => ['gold', 'bullion', 'precious metal', 'safe haven', 'xau'],
            ],
            // Shipping terms are intentionally folded into geopolitics or energy to avoid
            // one-day homepage duplication unless we later ingest a dedicated freight index.
            'ai-infrastructure' => [
                'title' => 'AI 基礎建設需求持續升溫',
                'category' => 'AI',
                'region' => 'Global',
                'sentiment' => 'positive',
                'themes' => ['AI Server', '雲端與資料中心', '半導體'],
                'industries' => ['半導體', '電腦及週邊設備', '軟體雲端'],
                'symbols' => ['2330', '2382', '3231', '6669', '2454'],
                'heat_bonus' => 8,
                'keywords' => ['nvidia', 'gpu', 'ai server', 'artificial intelligence', 'accelerated computing', 'data center', 'datacenter'],
            ],
            'fed-rates' => [
                'title' => '美國利率與金融政策影響市場情緒',
                'category' => 'Fed',
                'region' => 'US',
                'sentiment' => 'neutral',
                'themes' => ['金融與利率'],
                'industries' => ['金融'],
                'symbols' => [],
                'heat_bonus' => 5,
                'keywords' => ['federal reserve', 'fed', 'interest rate', 'inflation', 'cpi', 'treasury yield'],
            ],
            'apple-platform' => [
                'title' => 'Apple 服務與平台生態動態受關注',
                'category' => 'Apple',
                'region' => 'Global',
                'sentiment' => 'neutral',
                'themes' => ['Apple 生態系', '平台經濟'],
                'industries' => ['平台服務'],
                'symbols' => [],
                'heat_bonus' => 2,
                'keywords' => ['apple', 'iphone', 'app store', 'apple sports', 'vision pro'],
            ],
        ];
    }

    private function summary(Collection $events, ?array $definition): string
    {
        if ($definition !== null) {
            return match ($definition['category']) {
                'Geopolitics' => '戰爭、制裁或紅海等地緣消息升溫，可能影響油價、航運與市場風險偏好。',
                'Energy' => '油價、庫存或 OPEC 相關消息升溫，市場會觀察通膨與能源成本變化。',
                'Precious Metals' => '黃金與貴金屬消息增加，反映避險需求、美元與利率預期變化。',
                'Shipping' => '航運與貨櫃運價消息升溫，可能影響供應鏈成本與航運族群。',
                'AI' => 'AI 晶片、資料中心與伺服器需求仍是科技股與台股供應鏈焦點。',
                'Fed' => '美國利率、通膨與金融監管消息仍會牽動資金風險偏好。',
                'Apple' => 'Apple 服務與平台生態消息增加，市場會觀察服務營收與使用者黏著度。',
                default => $this->fallbackSummary($events),
            };
        }

        return $this->fallbackSummary($events);
    }

    private function fallbackTitle(Collection $events): string
    {
        return mb_substr((string) $events->first()->title, 0, 80);
    }

    private function fallbackSummary(Collection $events): string
    {
        return $events->pluck('title')->filter()->unique()->take(2)->implode('；');
    }

    private function sentiment(Collection $events): string
    {
        $text = Str::lower($events->map(fn ($event) => ($event->title ?? '').' '.($event->summary ?? ''))->implode(' '));

        if (Str::contains($text, ['war', 'attack', 'tariff', 'ban', 'restriction', 'higher rate'])) {
            return 'negative';
        }

        if (Str::contains($text, ['growth', 'record', 'demand', 'expand', 'partnership'])) {
            return 'positive';
        }

        return 'neutral';
    }
}
