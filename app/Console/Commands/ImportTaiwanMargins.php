<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockChip1d;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportTaiwanMargins extends Command
{
    protected $signature = 'market:import-margins
        {--date= : Trade date in YYYY-MM-DD. Defaults to latest stock price date.}';

    protected $description = 'Import official TWSE and TPEx margin trading balances.';

    private const TWSE_MARGIN_URL = 'https://www.twse.com.tw/exchangeReport/MI_MARGN';

    private const TPEX_MARGIN_URL = 'https://www.tpex.org.tw/www/zh-tw/margin/balance';

    public function handle(): int
    {
        $date = $this->tradeDate();

        $this->info('Importing margin balances for '.$date->toDateString());

        $twse = $this->importTwse($date);
        $tpex = $this->importTpex($date);

        $this->line('TWSE margin rows imported: '.$twse);
        $this->line('TPEx margin rows imported: '.$tpex);
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
            throw new \RuntimeException('No stock price data available. Import prices before margins.');
        }

        return CarbonImmutable::parse($latest, 'Asia/Taipei')->startOfDay();
    }

    private function importTwse(CarbonImmutable $date): int
    {
        $payload = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TWSE_MARGIN_URL, [
                'response' => 'json',
                'date' => $date->format('Ymd'),
                'selectType' => 'ALL',
            ])
            ->throw()
            ->json();

        if (($payload['stat'] ?? null) !== 'OK') {
            return 0;
        }

        $this->upsertTwseMarketSummary($date, $payload);

        $count = 0;
        $rows = $payload['tables'][1]['data'] ?? [];

        foreach ($rows as $row) {
            $symbol = trim((string) ($row[0] ?? ''));

            if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                continue;
            }

            $stock = Stock::query()->where('symbol', $symbol)->where('market', 'TWSE')->first();

            if (! $stock) {
                continue;
            }

            $this->upsertStockMargin($stock->id, $date, [
                'source' => 'TWSE_MI_MARGN',
                'row' => $row,
                'margin_balance' => $this->integer($row[6] ?? null),
                'short_balance' => $this->integer($row[12] ?? null),
            ]);

            $count++;
        }

        return $count;
    }

    private function importTpex(CarbonImmutable $date): int
    {
        $payload = Http::retry(3, 500)
            ->timeout(30)
            ->get(self::TPEX_MARGIN_URL, [
                'date' => $date->format('Y/m/d'),
                'response' => 'json',
            ])
            ->throw()
            ->json();

        $count = 0;
        $summary = $this->emptySummary();

        foreach (($payload['tables'][0]['data'] ?? []) as $row) {
            $symbol = trim((string) ($row[0] ?? ''));
            $marginBuy = $this->integer($row[3] ?? null);
            $marginSell = $this->integer($row[4] ?? null);
            $marginRepayment = $this->integer($row[5] ?? null);
            $marginPreviousBalance = $this->integer($row[2] ?? null);
            $marginBalance = $this->integer($row[6] ?? null);
            $shortSell = $this->integer($row[11] ?? null);
            $shortBuy = $this->integer($row[12] ?? null);
            $shortRepayment = $this->integer($row[13] ?? null);
            $shortPreviousBalance = $this->integer($row[10] ?? null);
            $shortBalance = $this->integer($row[14] ?? null);

            $summary['margin_buy'] += $marginBuy;
            $summary['margin_sell'] += $marginSell;
            $summary['margin_cash_repayment'] += $marginRepayment;
            $summary['margin_previous_balance'] += $marginPreviousBalance;
            $summary['margin_balance'] += $marginBalance;
            $summary['short_sell'] += $shortSell;
            $summary['short_buy'] += $shortBuy;
            $summary['short_repayment'] += $shortRepayment;
            $summary['short_previous_balance'] += $shortPreviousBalance;
            $summary['short_balance'] += $shortBalance;

            if (preg_match('/^\d{4}$/', $symbol) !== 1) {
                continue;
            }

            $stock = Stock::query()->where('symbol', $symbol)->where('market', 'TPEx')->first();

            if (! $stock) {
                continue;
            }

            $this->upsertStockMargin($stock->id, $date, [
                'source' => 'TPEX_MARGIN_BALANCE',
                'row' => $row,
                'margin_balance' => $marginBalance,
                'short_balance' => $shortBalance,
            ]);

            $count++;
        }

        $this->upsertMarketSummary('TPEx', $date, $summary + ['raw_payload' => ['source' => 'TPEX_MARGIN_BALANCE']]);

        return $count;
    }

    private function upsertTwseMarketSummary(CarbonImmutable $date, array $payload): void
    {
        $rows = $payload['tables'][0]['data'] ?? [];
        $margin = $rows[0] ?? [];
        $short = $rows[1] ?? [];

        $this->upsertMarketSummary('TWSE', $date, [
            'margin_buy' => $this->integer($margin[1] ?? null),
            'margin_sell' => $this->integer($margin[2] ?? null),
            'margin_cash_repayment' => $this->integer($margin[3] ?? null),
            'margin_previous_balance' => $this->integer($margin[4] ?? null),
            'margin_balance' => $this->integer($margin[5] ?? null),
            'short_sell' => $this->integer($short[1] ?? null),
            'short_buy' => $this->integer($short[2] ?? null),
            'short_repayment' => $this->integer($short[3] ?? null),
            'short_previous_balance' => $this->integer($short[4] ?? null),
            'short_balance' => $this->integer($short[5] ?? null),
            'raw_payload' => ['source' => 'TWSE_MI_MARGN', 'summary' => $rows],
        ]);
    }

    private function upsertMarketSummary(string $market, CarbonImmutable $date, array $data): void
    {
        DB::table('market_margins_1d')->updateOrInsert(
            ['market' => $market, 'trade_date' => $date->toDateString()],
            [
                'margin_buy' => $data['margin_buy'],
                'margin_sell' => $data['margin_sell'],
                'margin_cash_repayment' => $data['margin_cash_repayment'],
                'margin_previous_balance' => $data['margin_previous_balance'],
                'margin_balance' => $data['margin_balance'],
                'short_sell' => $data['short_sell'],
                'short_buy' => $data['short_buy'],
                'short_repayment' => $data['short_repayment'],
                'short_previous_balance' => $data['short_previous_balance'],
                'short_balance' => $data['short_balance'],
                'raw_payload' => json_encode($data['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function upsertStockMargin(int $stockId, CarbonImmutable $date, array $data): void
    {
        $chip = StockChip1d::query()->firstOrNew([
            'stock_id' => $stockId,
            'trade_date' => $date,
        ]);

        $rawPayload = $chip->raw_payload ?? [];
        $rawPayload['margin'] = ['source' => $data['source'], 'row' => $data['row']];

        $chip->fill([
            'margin_balance' => $data['margin_balance'],
            'short_balance' => $data['short_balance'],
            'raw_payload' => $rawPayload,
        ]);

        $chip->save();
    }

    private function emptySummary(): array
    {
        return [
            'margin_buy' => 0,
            'margin_sell' => 0,
            'margin_cash_repayment' => 0,
            'margin_previous_balance' => 0,
            'margin_balance' => 0,
            'short_sell' => 0,
            'short_buy' => 0,
            'short_repayment' => 0,
            'short_previous_balance' => 0,
            'short_balance' => 0,
        ];
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
