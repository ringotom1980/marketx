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

    protected $description = 'Map dynamic themes to stocks using conservative rule dictionaries, with AI hooks reserved.';

    public function handle(): int
    {
        $inserted = 0;
        $skipped = 0;

        foreach ($this->rules() as $slug => $rule) {
            $theme = Theme::query()->where('slug', $slug)->first();

            if (! $theme) {
                $skipped++;
                continue;
            }

            $stocks = $this->matchedStocks($rule);

            foreach ($stocks as $stock) {
                DB::table('stock_theme_map')->updateOrInsert(
                    ['stock_id' => $stock->id, 'theme_id' => $theme->id],
                    [
                        'weight' => $rule['weight'] ?? 60,
                        'reason' => $this->reason($rule, $this->option('use-ai')),
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
                    'rule' => $rule,
                ];

                $theme->update([
                    'ai_status' => 'mapping_queued',
                    'ai_payload' => $payload,
                ]);
            }
        }

        $this->info('Dynamic theme stock mappings upserted: '.$inserted);
        $this->line('Skipped rules: '.$skipped);

        return self::SUCCESS;
    }

    private function matchedStocks(array $rule)
    {
        $symbols = collect($rule['symbols'] ?? []);
        $industryKeywords = collect($rule['industries'] ?? [])->map(fn ($keyword) => Str::lower($keyword));
        $nameKeywords = collect($rule['names'] ?? [])->map(fn ($keyword) => Str::lower($keyword));

        return Stock::query()
            ->where('is_active', true)
            ->where(function ($query) use ($symbols, $industryKeywords, $nameKeywords) {
                if ($symbols->isNotEmpty()) {
                    $query->orWhereIn('symbol', $symbols->all());
                }

                foreach ($industryKeywords as $keyword) {
                    $query->orWhereRaw('lower(coalesce(industry, \'\')) like ?', ['%'.$keyword.'%']);
                }

                foreach ($nameKeywords as $keyword) {
                    $query->orWhereRaw('lower(name) like ?', ['%'.$keyword.'%']);
                }
            })
            ->limit($rule['limit'] ?? 80)
            ->get();
    }

    private function reason(array $rule, bool $useAi): string
    {
        $parts = [];

        if (! empty($rule['symbols'])) {
            $parts[] = 'seed symbols';
        }

        if (! empty($rule['industries'])) {
            $parts[] = 'industry keywords: '.implode(', ', $rule['industries']);
        }

        if (! empty($rule['names'])) {
            $parts[] = 'name keywords: '.implode(', ', $rule['names']);
        }

        $parts[] = $useAi ? 'AI mapping reserved' : 'rule-based dynamic mapping';

        return implode(' | ', $parts);
    }

    private function rules(): array
    {
        return [
            'ai-server' => [
                'symbols' => ['2317', '2356', '2376', '2382', '3231', '6669', '2357', '2377', '4938'],
                'industries' => ['電腦及週邊設備', '其他電子'],
                'names' => ['廣達', '緯創', '英業達', '技嘉', '華碩', '鴻海', '勤誠'],
                'weight' => 70,
                'limit' => 80,
            ],
            'thermal' => [
                'symbols' => ['3017', '3324', '3653', '2421', '6230', '8996'],
                'names' => ['奇鋐', '雙鴻', '健策', '建準', '尼得科', '高力'],
                'weight' => 78,
                'limit' => 60,
            ],
            'cowos' => [
                'symbols' => ['2330', '3711', '2449', '3264', '6515', '3131', '3583', '6789'],
                'industries' => ['半導體'],
                'names' => ['台積電', '日月光', '京元電', '弘塑', '辛耘', '萬潤'],
                'weight' => 72,
                'limit' => 80,
            ],
            'optical-communication' => [
                'symbols' => ['3081', '3363', '4979', '4908', '3163', '3450', '3234', '6442'],
                'industries' => ['通信網路', '光電'],
                'names' => ['聯亞', '波若威', '華星光', '光環', '上詮'],
                'weight' => 72,
                'limit' => 80,
            ],
            'pcb' => [
                'symbols' => ['3037', '3044', '3189', '5469', '6191', '6274', '8358', '2368'],
                'industries' => ['電子零組件'],
                'names' => ['欣興', '健鼎', '景碩', '南電', '台光電', '金像電', '定穎'],
                'weight' => 66,
                'limit' => 90,
            ],
            'robotics' => [
                'symbols' => ['2049', '2359', '2360', '2464', '3013', '3374', '4576'],
                'industries' => ['電機機械'],
                'names' => ['機器人', '自動化', '上銀', '亞德客', '直得', '羅昇'],
                'weight' => 62,
                'limit' => 70,
            ],
            'power-grid' => [
                'symbols' => ['1503', '1513', '1514', '1519', '1605', '1618', '2371'],
                'industries' => ['電器電纜', '電機機械'],
                'names' => ['重電', '華城', '中興電', '亞力', '士電', '大亞'],
                'weight' => 68,
                'limit' => 80,
            ],
            'semiconductor-equipment' => [
                'symbols' => ['3167', '3131', '3583', '6187', '6515', '6640', '6789'],
                'industries' => ['半導體'],
                'names' => ['設備', '辛耘', '弘塑', '萬潤', '家登', '帆宣'],
                'weight' => 62,
                'limit' => 80,
            ],
            'memory-hbm' => [
                'symbols' => ['2344', '2408', '3006', '3260', '6239'],
                'industries' => ['半導體'],
                'names' => ['南亞科', '華邦電', '群聯', '威剛', '記憶體'],
                'weight' => 60,
                'limit' => 70,
            ],
            'ai-pc' => [
                'symbols' => ['2353', '2356', '2357', '2376', '2382', '2395', '3231'],
                'industries' => ['電腦及週邊設備'],
                'names' => ['宏碁', '華碩', '微星', '技嘉', '研華'],
                'weight' => 62,
                'limit' => 80,
            ],
            'ev' => [
                'symbols' => ['1536', '1568', '2231', '2308', '2317', '2327', '3665'],
                'industries' => ['汽車', '電子零組件', '電機機械'],
                'names' => ['車', '充電', '胡連', '貿聯', '台達電'],
                'weight' => 58,
                'limit' => 90,
            ],
            'green-energy' => [
                'symbols' => ['1519', '1609', '1618', '3576', '6443', '6806'],
                'industries' => ['光電', '電器電纜', '油電燃氣'],
                'names' => ['綠能', '太陽能', '風電', '儲能', '元晶', '聯合再生'],
                'weight' => 58,
                'limit' => 90,
            ],
            'aerospace-defense' => [
                'symbols' => ['2634', '2645', '4572', '8033'],
                'industries' => ['航運', '電機機械', '其他'],
                'names' => ['漢翔', '龍德', '雷虎', '無人機', '航太'],
                'weight' => 60,
                'limit' => 60,
            ],
            'biotech' => [
                'industries' => ['生技醫療'],
                'names' => ['藥', '醫', '生技'],
                'weight' => 58,
                'limit' => 90,
            ],
            'shipping' => [
                'industries' => ['航運'],
                'names' => ['航', '海運', '貨櫃', '散裝'],
                'weight' => 58,
                'limit' => 80,
            ],
            'financial' => [
                'industries' => ['金融保險'],
                'names' => ['金', '銀', '保'],
                'weight' => 55,
                'limit' => 90,
            ],
        ];
    }
}
