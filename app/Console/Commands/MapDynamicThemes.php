<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MapDynamicThemes extends Command
{
    protected $signature = 'market:map-dynamic-themes {--use-ai : Reserved switch for future AI-assisted stock mapping}';

    protected $description = 'Map themes to stocks using the maintainable theme library, with AI hooks reserved.';

    public function handle(): int
    {
        $library = require database_path('theme_library.php');
        $inserted = 0;
        $skipped = 0;

        foreach ($library as $definition) {
            $theme = Theme::query()->where('slug', $definition['slug'])->first();

            if (! $theme) {
                $skipped++;
                continue;
            }

            $stocks = $this->matchedStocks($definition);

            foreach ($stocks as $stock) {
                DB::table('stock_theme_map')->updateOrInsert(
                    ['stock_id' => $stock->id, 'theme_id' => $theme->id],
                    [
                        'weight' => (int) ($definition['mapping_weight'] ?? 60),
                        'reason' => $this->reason($definition, $this->option('use-ai')),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $inserted++;
            }

            if ($this->option('use-ai')) {
                $payload = $theme->ai_payload ?? [];
                $payload['stock_mapping_prompt'] = [
                    'reserved' => true,
                    'task' => 'map theme to Taiwan stocks',
                    'theme' => $theme->name,
                    'definition' => $definition,
                ];

                $theme->update([
                    'ai_status' => 'mapping_queued',
                    'ai_payload' => $payload,
                ]);
            }
        }

        $this->info('Dynamic theme stock mappings upserted: '.$inserted);
        $this->line('Skipped themes: '.$skipped);

        return self::SUCCESS;
    }

    private function matchedStocks(array $definition)
    {
        $symbols = collect($definition['symbols'] ?? [])->map(fn ($symbol) => (string) $symbol);
        $nameKeywords = collect($definition['map_keywords'] ?? $definition['keywords'] ?? [])
            ->map(fn ($keyword) => Str::lower((string) $keyword))
            ->filter(fn ($keyword) => mb_strlen($keyword) >= 2)
            ->values();

        if ($symbols->isEmpty() && $nameKeywords->isEmpty()) {
            return collect();
        }

        return Stock::query()
            ->where('is_active', true)
            ->where(function ($query) use ($symbols, $nameKeywords) {
                if ($symbols->isNotEmpty()) {
                    $query->orWhereIn('symbol', $symbols->all());
                }

                foreach ($nameKeywords as $keyword) {
                    $query->orWhereRaw('lower(name) like ?', ['%'.$keyword.'%']);
                    $query->orWhereRaw('lower(coalesce(industry, \'\')) like ?', ['%'.$keyword.'%']);
                }
            })
            ->limit((int) ($definition['mapping_limit'] ?? 120))
            ->get();
    }

    private function reason(array $definition, bool $useAi): string
    {
        $parts = [];

        if (! empty($definition['symbols'])) {
            $parts[] = 'library symbols';
        }

        if (! empty($definition['map_keywords']) || ! empty($definition['keywords'])) {
            $parts[] = 'library keywords';
        }

        $parts[] = $useAi ? 'AI mapping reserved' : 'rule-based library mapping';

        return implode(' | ', $parts);
    }
}
