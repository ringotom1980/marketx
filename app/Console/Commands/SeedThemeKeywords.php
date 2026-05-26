<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedThemeKeywords extends Command
{
    protected $signature = 'market:seed-theme-keywords';

    protected $description = 'Sync the maintainable Taiwan market theme library and keyword dictionary.';

    public function handle(): int
    {
        $library = require database_path('theme_library.php');
        $themeCount = 0;
        $keywordCount = 0;

        foreach ($library as $definition) {
            $theme = Theme::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'source' => 'library',
                    'is_active' => true,
                ],
            );
            $themeCount++;

            foreach (($definition['keywords'] ?? []) as $keyword) {
                DB::table('theme_keywords')->updateOrInsert(
                    ['theme_id' => $theme->id, 'keyword' => $keyword],
                    [
                        'weight' => (int) ($definition['keyword_weight'] ?? 70),
                        'source' => 'library',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $keywordCount++;
            }
        }

        $this->info('Theme library synced: '.$themeCount);
        $this->line('Theme keywords synced: '.$keywordCount);

        return self::SUCCESS;
    }
}
