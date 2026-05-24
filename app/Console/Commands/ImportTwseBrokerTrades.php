<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportTwseBrokerTrades extends Command
{
    protected $signature = 'market:import-broker-trades
        {--symbol= : Probe one TWSE stock symbol}
        {--date= : Trade date YYYY-MM-DD. BSR public query only provides current trading day.}';

    protected $description = 'Probe official TWSE broker branch daily report source and record whether automated import is available.';

    private const BSR_WELCOME_URL = 'https://bsr.twse.com.tw/bshtm/bsWelcome.aspx';

    private const BSR_MENU_URL = 'https://bsr.twse.com.tw/bshtm/bsMenu.aspx';

    public function handle(): int
    {
        $symbol = (string) ($this->option('symbol') ?: '');
        $this->info('Checking TWSE official broker branch daily report source...');

        $welcome = Http::retry(2, 500)
            ->timeout(20)
            ->withHeaders($this->headers())
            ->get(self::BSR_WELCOME_URL);

        if (! $welcome->ok()) {
            $this->record('error', 'TWSE BSR welcome request failed.', [
                'status' => $welcome->status(),
                'symbol' => $symbol ?: null,
            ]);
            $this->error('TWSE BSR welcome request failed: HTTP '.$welcome->status());

            return self::FAILURE;
        }

        $menu = Http::retry(2, 500)
            ->timeout(20)
            ->withHeaders($this->headers())
            ->get(self::BSR_MENU_URL);

        if (! $menu->ok()) {
            $this->record('error', 'TWSE BSR menu request failed.', [
                'status' => $menu->status(),
                'symbol' => $symbol ?: null,
            ]);
            $this->error('TWSE BSR menu request failed: HTTP '.$menu->status());

            return self::FAILURE;
        }

        $body = $menu->body();
        $captchaRequired = str_contains($body, '輸入圖形中5碼文數字')
            || str_contains($body, '驗證碼')
            || str_contains($body, 'Captcha')
            || str_contains($body, 'captcha');

        if ($captchaRequired) {
            $message = 'TWSE BSR public query requires per-symbol captcha; automatic scraping is blocked.';
            $this->record('warning', $message, [
                'symbol' => $symbol ?: null,
                'source' => self::BSR_MENU_URL,
                'official_note' => '查詢每一檔證券前均輸入驗證碼',
                'fallback_command' => 'market:import-broker-trades-csv',
            ]);

            $this->warn($message);
            $this->line('Use official TWSE eShop daily report files with:');
            $this->line('php artisan market:import-broker-trades-csv <file> --market=TWSE --date=YYYY-MM-DD');

            return self::SUCCESS;
        }

        $this->record('info', 'TWSE BSR source did not expose captcha marker; parser not executed.', [
            'symbol' => $symbol ?: null,
            'source' => self::BSR_MENU_URL,
        ]);

        $this->warn('TWSE BSR page shape changed or captcha marker not found. No trades imported.');

        return self::SUCCESS;
    }

    private function headers(): array
    {
        return [
            'User-Agent' => 'MarketX/1.0 official-data-check',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
    }

    private function record(string $level, string $message, array $context): void
    {
        SystemLog::query()->create([
            'level' => $level,
            'source' => 'twse_bsr',
            'message' => $message,
            'context' => $context,
        ]);
    }
}
