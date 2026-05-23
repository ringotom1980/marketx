<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Illuminate\Console\Command;

class SeedThemes extends Command
{
    protected $signature = 'market:seed-themes';

    protected $description = 'Seed initial theme definitions from the product plan.';

    public function handle(): int
    {
        $themes = [
            ['name' => 'AI 伺服器', 'slug' => 'ai-server', 'description' => 'AI 伺服器、雲端資料中心、GPU 主機板與系統組裝供應鏈。'],
            ['name' => '散熱', 'slug' => 'thermal', 'description' => '風扇、散熱模組、液冷、均熱片與高功耗伺服器散熱供應鏈。'],
            ['name' => 'CoWoS 先進封裝', 'slug' => 'cowos', 'description' => '台積電 CoWoS、先進封裝、測試、探針卡、設備與相關材料。'],
            ['name' => '光通訊', 'slug' => 'optical-communication', 'description' => '光模組、矽光子、高速傳輸、資料中心網通與光纖通訊供應鏈。'],
        ];

        foreach ($themes as $theme) {
            Theme::query()->updateOrCreate(
                ['slug' => $theme['slug']],
                $theme + ['is_active' => true],
            );
        }

        $this->info('Themes seeded: '.count($themes));

        return self::SUCCESS;
    }
}
