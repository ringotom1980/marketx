<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockChip1d;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanChips extends Command
{
    protected $signature = 'market:import-chips
        {--date= : Trade date in YYYY-MM-DD. Defaults to latest stock price date.}';

    protected $description = 'Import official TWSE and TPEx institutional chip data.';

    private const TWSE_T86_URL = 'https://www.twse.com.tw/rwd/zh/fund/T86';

    private const TPEX_DAILY_TRADE_URL = 'https://www.tpex.org.tw/www/zh-tw/insti/dailyTrade';

    public function handle(): int
    {
        $date = $this->tradeDate();

        $this->info('Importing institutional chip data for '.$date->toDateString());

        $twse = $this->importTwseInstitutional($date);
        $tpex = $this->importTpexInstitutional($date);

        $this->line('TWSE chip rows imported: '.$twse);
        $this->line('TPEx chip rows imported: '.$tpex);
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function tradeDate(): CarbonImmutable
    {
        if ($this->option('date')) {
            return CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->startOfDay();
        }

        $latest = DB::table('stock_prices_1d')->max('trade_date');

        if (! $latest) {
            throw new \RuntimeException('No stock price data available. Import prices before chips.');
        }

        return CarbonImmutable::parse($latest, 'Asia/Taipei')->startOfDay();
    }

    private function importTwseInstitutional(CarbonImmutable $date): int
    {
        $payload = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TWSE_T86_URL, [
                'response' => 'json',
                'date' => $date->format('Ymd'),
                'selectType' => 'ALL',
            ])
            ->throw()
            ->json();

        if (($payload['stat'] ?? null) !== 'OK') {
            return 0;
        }

        $count = 0;

        foreach (($payload['data'] ?? []) as $row) {
            $symbol = trim((string) ($row[0] ?? ''));
            $stock = Stock::query()->where('symbol', $symbol)->where('market', 'TWSE')->first();

            if (! $stock) {
                continue;
            }

            $foreignNet = $this->integer($row[4] ?? null) + $this->integer($row[7] ?? null);
            $investmentTrustNet = $this->integer($row[10] ?? null);
            $dealerNet = $this->integer($row[11] ?? null);
            $institutionalNet = $this->integer($row[18] ?? null);

            StockChip1d::query()->updateOrCreate(
                ['stock_id' => $stock->id, 'trade_date' => $date],
                [
                    'foreign_net_buy' => $foreignNet,
                    'investment_trust_net_buy' => $investmentTrustNet,
                    'dealer_net_buy' => $dealerNet,
                    'institutional_net_buy' => $institutionalNet,
                    'raw_payload' => ['source' => 'TWSE_T86', 'row' => $row],
                ],
            );

            $count++;
        }

        return $count;
    }

    private function importTpexInstitutional(CarbonImmutable $date): int
    {
        $payload = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TPEX_DAILY_TRADE_URL, [
                'date' => $date->format('Y/m/d'),
                'type' => 'Daily',
                'response' => 'json',
            ])
            ->throw()
            ->json();

        $count = 0;

        foreach (($payload['tables'][0]['data'] ?? []) as $row) {
            $symbol = trim((string) ($row[0] ?? ''));
            $stock = Stock::query()->where('symbol', $symbol)->where('market', 'TPEx')->first();

            if (! $stock) {
                continue;
            }

            $foreignNet = $this->integer($row[10] ?? null);
            $investmentTrustNet = $this->integer($row[13] ?? null);
            $dealerNet = $this->integer($row[22] ?? null);
            $institutionalNet = $this->integer($row[23] ?? null);

            StockChip1d::query()->updateOrCreate(
                ['stock_id' => $stock->id, 'trade_date' => $date],
                [
                    'foreign_net_buy' => $foreignNet,
                    'investment_trust_net_buy' => $investmentTrustNet,
                    'dealer_net_buy' => $dealerNet,
                    'institutional_net_buy' => $institutionalNet,
                    'raw_payload' => ['source' => 'TPEX_DAILY_TRADE', 'row' => $row],
                ],
            );

            $count++;
        }

        return $count;
    }

    private function integer(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        $value = str_replace([',', '+'], '', trim((string) $value));

        if ($value === '' || $value === '-' || $value === '--') {
            return 0;
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}

