<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedThemeMappings extends Command
{
    protected $signature = 'market:seed-theme-mappings';

    protected $description = 'Seed curated Taiwan stock-to-theme mappings used by the Theme Engine.';

    public function handle(): int
    {
        $maps = [
            'ai-server' => [
                ['2382', 90, 'AI 伺服器組裝與雲端資料中心主機供應鏈'],
                ['3231', 90, 'AI 伺服器與資料中心系統整合供應鏈'],
                ['6669', 92, '雲端資料中心與 AI 伺服器出貨代表廠'],
                ['2356', 78, '伺服器與企業級電腦供應鏈'],
                ['2376', 76, 'AI PC、伺服器主機板與高速運算平台'],
                ['2357', 72, 'AI PC、伺服器與主機板平台'],
                ['2317', 70, '雲端、伺服器與 AI 相關硬體製造供應鏈'],
            ],
            'thermal' => [
                ['3017', 92, '伺服器散熱模組與液冷題材代表'],
                ['3324', 90, '高階散熱模組與 AI 伺服器散熱供應鏈'],
                ['3653', 84, '均熱片、散熱與高功耗平台材料'],
                ['2421', 72, '風扇與散熱零組件供應鏈'],
                ['6230', 76, '伺服器散熱模組供應鏈'],
                ['8996', 70, '熱交換與散熱相關應用'],
            ],
            'cowos' => [
                ['2330', 95, 'CoWoS 與先進製程核心供應鏈'],
                ['3711', 82, '封裝測試與先進封裝供應鏈'],
                ['2449', 78, '半導體測試與 AI 晶片測試供應鏈'],
                ['3264', 72, '晶圓測試與半導體測試服務'],
                ['6515', 78, '探針卡與高階測試介面供應鏈'],
                ['3131', 72, '半導體濕製程與先進封裝設備'],
                ['3583', 72, '半導體設備與先進封裝相關供應鏈'],
                ['6789', 68, '影像感測與半導體製程供應鏈'],
            ],
            'optical-communication' => [
                ['3081', 82, '光通訊與高速傳輸供應鏈'],
                ['3363', 78, '光纖與資料中心通訊供應鏈'],
                ['4979', 82, '光通訊模組與資料中心傳輸題材'],
                ['4908', 70, '光通訊與網通設備供應鏈'],
                ['3163', 76, '光通訊與高速網路供應鏈'],
                ['3450', 72, '光通訊元件與高速傳輸應用'],
                ['3234', 70, '光通訊元件供應鏈'],
                ['6442', 76, '光通訊與資料中心高速傳輸供應鏈'],
            ],
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($maps as $slug => $rows) {
            $theme = Theme::query()->where('slug', $slug)->first();

            if (! $theme) {
                $skipped += count($rows);
                continue;
            }

            foreach ($rows as [$symbol, $weight, $reason]) {
                $stockId = Stock::query()->where('symbol', $symbol)->value('id');

                if (! $stockId) {
                    $skipped++;
                    continue;
                }

                DB::table('stock_theme_map')->updateOrInsert(
                    ['stock_id' => $stockId, 'theme_id' => $theme->id],
                    [
                        'weight' => $weight,
                        'reason' => $reason,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                $inserted++;
            }
        }

        $this->info('Theme mappings upserted: '.$inserted);
        $this->line('Skipped mappings: '.$skipped);

        return self::SUCCESS;
    }
}
