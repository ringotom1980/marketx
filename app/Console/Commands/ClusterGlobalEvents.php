<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClusterGlobalEvents extends Command
{
    protected $signature = 'market:cluster-global-events
        {--days=2 : Recent event lookback days}
        {--limit=200 : Max raw events to cluster}';

    protected $description = 'Cluster raw global events with deterministic rules before AI preprocessing.';

    public function handle(): int
    {
        $days = max(1, min(14, (int) $this->option('days')));
        $limit = max(20, min(1000, (int) $this->option('limit')));
        $from = CarbonImmutable::now('Asia/Taipei')->subDays($days);
        $clusterDate = CarbonImmutable::now('Asia/Taipei')->toDateString();

        $events = DB::table('global_events')
            ->where(function ($query) use ($from) {
                $query->whereNull('event_date')
                    ->orWhere('event_date', '>=', $from);
            })
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'event_date', 'source', 'title', 'summary', 'category', 'region', 'impact_score']);

        $clusters = $events
            ->groupBy(fn ($event) => $this->primaryClusterKey($event))
            ->map(fn ($group, string $key) => $this->clusterPayload($key, $group->values(), $clusterDate))
            ->sortByDesc('importance_score')
            ->values();

        $upserted = 0;

        foreach ($clusters as $cluster) {
            DB::table('global_event_clusters')->updateOrInsert(
                ['cluster_date' => $clusterDate, 'cluster_key' => $cluster['cluster_key']],
                [
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
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $upserted++;
        }

        $this->info('Global event clusters upserted: '.$upserted);

        return self::SUCCESS;
    }

    private function clusterKey(object $event): string
    {
        $text = Str::lower(($event->title ?? '').' '.($event->summary ?? '').' '.($event->category ?? ''));
        $category = $event->category ?: $this->category($text);
        $topic = $this->topic($text);

        return Str::slug($category.'-'.$topic) ?: 'global-general';
    }

    private function primaryClusterKey(object $event): string
    {
        $text = Str::lower(($event->title ?? '').' '.($event->summary ?? '').' '.($event->category ?? ''));

        if (str_contains($text, 'ai') || str_contains($text, 'nvidia') || str_contains($text, 'gpu') || str_contains($text, 'server') || str_contains($text, 'cloud')) {
            return 'ai-infrastructure';
        }

        return $this->clusterKey($event);
    }

    private function clusterPayload(string $key, mixed $events, string $clusterDate): array
    {
        $first = $events->first();
        $text = Str::lower($events->map(fn ($event) => ($event->title ?? '').' '.($event->summary ?? '').' '.($event->category ?? ''))->implode(' '));
        $themes = $this->themes($text);
        $industries = $this->industries($text);
        $importance = min(100, max(20, 35 + ($events->count() * 8) + count($themes) * 7 + count($industries) * 4));

        return [
            'cluster_date' => $clusterDate,
            'cluster_key' => $key,
            'title' => $this->title($first, $themes),
            'summary' => $this->summary($events),
            'category' => $first->category ?: $this->category($text),
            'region' => $first->region ?: $this->region($text),
            'importance_score' => $importance,
            'sentiment' => $this->sentiment($text, $themes),
            'themes' => $themes,
            'industries' => $industries,
            'related_symbols' => $this->symbols($text),
            'event_ids' => $events->pluck('id')->values()->all(),
        ];
    }

    private function title(object $event, array $themes): string
    {
        if ($themes !== []) {
            return $themes[0].'事件升溫';
        }

        return mb_substr((string) $event->title, 0, 80);
    }

    private function summary(mixed $events): string
    {
        $titles = $events
            ->pluck('title')
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return implode('；', $titles);
    }

    private function topic(string $text): string
    {
        foreach ([
            'ai' => ['ai', 'nvidia', 'gpu', 'accelerated computing', 'server'],
            'fed' => ['fed', 'federal reserve', 'rate', 'inflation', 'cpi'],
            'apple' => ['apple', 'iphone', 'vision pro'],
            'platform' => ['app store', 'sports expands', 'fraudulent transactions', 'subscription', 'stream'],
            'microsoft' => ['microsoft', 'azure', 'copilot'],
            'china' => ['china', 'export', 'tariff', 'geopolitical'],
            'energy' => ['oil', 'crude', 'brent', 'wti', 'opec', 'fuel', 'gasoline', 'power'],
            'precious-metals' => ['gold', 'precious metal', 'safe haven', 'bullion'],
            'shipping' => ['shipping', 'freight', 'container', 'baltic dry', 'red sea'],
        ] as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $topic;
                }
            }
        }

        return 'general';
    }

    private function category(string $text): string
    {
        return match ($this->topic($text)) {
            'ai' => 'AI',
            'fed' => 'Fed',
            'apple' => 'Apple',
            'platform' => 'Platform',
            'microsoft' => 'Microsoft',
            'china' => 'Geopolitics',
            'energy' => 'Energy',
            'precious-metals' => 'Precious Metals',
            'shipping' => 'Shipping',
            default => 'Global',
        };
    }

    private function region(string $text): string
    {
        return str_contains($text, 'china') ? 'China' : 'Global';
    }

    private function themes(string $text): array
    {
        $themes = [];

        foreach ([
            'AI Server' => ['ai', 'server', 'gpu', 'nvidia'],
            'CoWoS 先進封裝' => ['cowos', 'advanced packaging', 'packaging'],
            '散熱' => ['thermal', 'cooling', 'heat'],
            '半導體' => ['semiconductor', 'chip', 'foundry'],
            '雲端與資料中心' => ['cloud', 'azure', 'datacenter', 'data center'],
            '金融與利率' => ['fed', 'rate', 'inflation'],
            '地緣政治' => ['china', 'export', 'tariff', 'geopolitical'],
            '能源' => ['oil', 'crude', 'brent', 'wti', 'opec', 'fuel', 'power', 'energy'],
            '貴金屬' => ['gold', 'precious metal', 'safe haven', 'bullion'],
            '航運運費' => ['shipping', 'freight', 'container', 'baltic dry', 'red sea'],
            'Apple 生態系' => ['apple', 'iphone', 'app store', 'sports expands'],
            '平台經濟' => ['app store', 'stream', 'subscription', 'fraudulent transactions'],
        ] as $theme => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $themes[] = $theme;
                    break;
                }
            }
        }

        return array_values(array_unique($themes));
    }

    private function industries(string $text): array
    {
        $industries = [];

        foreach ([
            '半導體' => ['semiconductor', 'chip', 'foundry', 'gpu'],
            '電腦及週邊設備' => ['server', 'pc', 'datacenter', 'data center'],
            '軟體雲端' => ['cloud', 'azure', 'copilot'],
            '金融' => ['fed', 'rate', 'inflation'],
            '能源' => ['oil', 'crude', 'energy', 'power'],
            '航運' => ['shipping', 'freight', 'container', 'baltic dry', 'red sea'],
            '貴金屬' => ['gold', 'precious metal', 'safe haven', 'bullion'],
            '平台服務' => ['apple', 'app store', 'stream', 'subscription'],
        ] as $industry => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $industries[] = $industry;
                    break;
                }
            }
        }

        return array_values(array_unique($industries));
    }

    private function symbols(string $text): array
    {
        $symbols = [];

        foreach ([
            '2330' => ['tsmc', 'foundry', 'semiconductor', 'cowos'],
            '2382' => ['server', 'ai server', 'datacenter'],
            '3231' => ['server', 'ai server', 'datacenter'],
            '6669' => ['server', 'ai server'],
            '2454' => ['semiconductor', 'chip'],
        ] as $symbol => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $symbols[] = $symbol;
                    break;
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    private function sentiment(string $text, array $themes): string
    {
        $negative = ['restriction', 'ban', 'tariff', 'war', 'geopolitical risk', 'higher rate'];
        $positive = ['growth', 'strong', 'record', 'demand', 'expand', 'partnership'];

        foreach ($negative as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'negative';
            }
        }

        foreach ($positive as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'positive';
            }
        }

        if (array_intersect($themes, ['AI Server', '雲端與資料中心', '半導體']) !== []) {
            return 'positive';
        }

        return 'neutral';
    }
}
