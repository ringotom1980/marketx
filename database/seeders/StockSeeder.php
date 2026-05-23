<?php

namespace Database\Seeders;

use App\Models\Stock;
use Illuminate\Database\Seeder;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = [
            ['symbol' => '2330', 'name' => '台積電', 'market' => 'TWSE', 'industry' => '半導體'],
            ['symbol' => '2382', 'name' => '廣達', 'market' => 'TWSE', 'industry' => '電腦及週邊設備'],
            ['symbol' => '2376', 'name' => '技嘉', 'market' => 'TWSE', 'industry' => '電腦及週邊設備'],
            ['symbol' => '3231', 'name' => '緯創', 'market' => 'TWSE', 'industry' => '電腦及週邊設備'],
            ['symbol' => '3017', 'name' => '奇鋐', 'market' => 'TWSE', 'industry' => '電子零組件'],
            ['symbol' => '3324', 'name' => '雙鴻', 'market' => 'TPEx', 'industry' => '電子零組件'],
        ];

        foreach ($stocks as $stock) {
            Stock::query()->updateOrCreate(
                ['symbol' => $stock['symbol']],
                $stock + ['is_active' => true],
            );
        }
    }
}

