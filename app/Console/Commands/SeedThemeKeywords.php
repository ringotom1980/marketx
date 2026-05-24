<?php

namespace App\Console\Commands;

use App\Models\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedThemeKeywords extends Command
{
    protected $signature = 'market:seed-theme-keywords';

    protected $description = 'Seed baseline keyword dictionary for the rule-based dynamic theme engine.';

    public function handle(): int
    {
        $themes = [
            'ai-server' => ['AI Server', 'AI伺服器', 'GB200', 'GB300', 'NVIDIA', '輝達', 'GPU伺服器', '液冷伺服器'],
            'thermal' => ['散熱', '液冷', '水冷', '均熱片', '熱管', '風扇', '伺服器散熱'],
            'cowos' => ['CoWoS', '先進封裝', '封裝產能', 'SoIC', '2.5D封裝'],
            'optical-communication' => ['光通訊', '矽光子', 'CPO', '光收發模組', '800G', '1.6T'],
            'pcb' => ['PCB', 'HDI', 'ABF', '載板', '高階板', '伺服器板'],
            'robotics' => ['機器人', '人形機器人', 'Robotaxi', '自動化', '伺服馬達'],
            'power-grid' => ['重電', '電力設備', '變壓器', '電網', '儲能', '電力基建'],
            'semiconductor-equipment' => ['半導體設備', '晶圓設備', '蝕刻', '檢測設備', '曝光', '再生晶圓'],
            'memory-hbm' => ['HBM', 'DRAM', '記憶體', 'NAND', '高頻寬記憶體'],
            'ai-pc' => ['AI PC', 'NPU', '筆電', 'PC換機潮', 'Copilot'],
            'ev' => ['電動車', '車用電子', '充電樁', 'ADAS', '車用半導體'],
            'green-energy' => ['綠能', '太陽能', '風電', '儲能', '碳權'],
            'aerospace-defense' => ['軍工', '航太', '無人機', '國防', '雷達'],
            'biotech' => ['生技', '新藥', '醫材', 'CDMO', '臨床試驗'],
            'shipping' => ['航運', '貨櫃', '散裝', '運價', 'BDI'],
            'financial' => ['金融', '升息', '降息', '銀行', '保險', '殖利率'],
        ];

        $count = 0;

        foreach ($themes as $slug => $keywords) {
            $theme = Theme::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $this->name($slug),
                    'description' => '由關鍵字與事件動態維護的題材。',
                    'source' => 'seed',
                    'is_active' => true,
                ],
            );

            foreach ($keywords as $keyword) {
                DB::table('theme_keywords')->updateOrInsert(
                    ['theme_id' => $theme->id, 'keyword' => $keyword],
                    [
                        'weight' => 70,
                        'source' => 'seed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $count++;
            }
        }

        $this->info('Theme keywords seeded: '.$count);

        return self::SUCCESS;
    }

    private function name(string $slug): string
    {
        return match ($slug) {
            'ai-server' => 'AI Server',
            'thermal' => '散熱',
            'cowos' => 'CoWoS',
            'optical-communication' => '光通訊',
            'pcb' => 'PCB',
            'robotics' => '機器人',
            'power-grid' => '重電',
            'semiconductor-equipment' => '半導體設備',
            'memory-hbm' => 'HBM 記憶體',
            'ai-pc' => 'AI PC',
            'ev' => '電動車',
            'green-energy' => '綠能',
            'aerospace-defense' => '軍工航太',
            'biotech' => '生技醫療',
            'shipping' => '航運',
            'financial' => '金融',
            default => Str::headline($slug),
        };
    }
}
