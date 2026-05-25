<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BackfillTaifexNightSession extends Command
{
    protected $signature = 'market:backfill-taifex-night
        {--days=365 : Calendar days to backfill}
        {--from= : Start date, YYYY-MM-DD}
        {--to= : End date, YYYY-MM-DD}';

    protected $description = 'Backfill TAIFEX TX after-hours daily OHLC from official futures daily market download.';

    private const URL = 'https://www.taifex.com.tw/cht/3/futDataDown';

    public function handle(): int
    {
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), 'Asia/Taipei')
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $to = $to instanceof CarbonImmutable ? $to : CarbonImmutable::parse($to, 'Asia/Taipei');

        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'), 'Asia/Taipei')
            : $to->subDays((int) $this->option('days'));

        if ($from->greaterThan($to)) {
            $this->error('Invalid range: from date is later than to date.');

            return self::FAILURE;
        }

        $imported = 0;
        $chunks = $this->monthlyChunks($from, $to);

        foreach ($chunks as [$chunkStart, $chunkEnd]) {
            $rows = $this->fetchChunk($chunkStart, $chunkEnd);

            if ($rows->isEmpty()) {
                $this->warn("No TAIFEX rows for {$chunkStart->toDateString()} ~ {$chunkEnd->toDateString()}.");
                continue;
            }

            $selected = $rows
                ->filter(fn ($row) => ($row['契約'] ?? null) === 'TX')
                ->filter(fn ($row) => ($row['交易時段'] ?? null) === '盤後')
                ->filter(fn ($row) => $this->decimal($row['收盤價'] ?? null) !== null)
                ->groupBy('交易日期')
                ->map(fn ($group) => $group
                    ->sortBy(fn ($row) => (string) ($row['到期月份(週別)'] ?? '999999'))
                    ->first());

            foreach ($selected as $row) {
                $tradeDate = $this->date($row['交易日期'] ?? null);
                $close = $this->decimal($row['收盤價'] ?? null);

                if (! $tradeDate || $close === null) {
                    continue;
                }

                $open = $this->decimal($row['開盤價'] ?? null) ?? $close;
                $high = $this->decimal($row['最高價'] ?? null) ?? max($open, $close);
                $low = $this->decimal($row['最低價'] ?? null) ?? min($open, $close);
                $change = $this->decimal($row['漲跌價'] ?? null);
                $changePct = $this->percent($row['漲跌%'] ?? null);
                $volume = (int) ($this->decimal($row['成交量'] ?? null) ?? 0);

                DB::table('global_market_data')->updateOrInsert(
                    ['indicator' => 'TAIFEX TX Night', 'trade_date' => $tradeDate],
                    [
                        'value' => $close,
                        'change' => $change,
                        'change_pct' => $changePct,
                        'state' => $this->state($changePct),
                        'source' => 'TAIFEX Futures Daily Market Download',
                        'raw_payload' => json_encode([
                            'open' => $open,
                            'high' => max($high, $open, $close),
                            'low' => min($low, $open, $close),
                            'close' => $close,
                            'volume' => $volume,
                            'contract' => 'TX',
                            'contract_month' => $row['到期月份(週別)'] ?? null,
                            'trading_session' => '盤後',
                            'official_row' => $row,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $imported++;
            }

            $this->info("TAIFEX TX night chunk imported: {$chunkStart->toDateString()} ~ {$chunkEnd->toDateString()}.");
        }

        $this->info("TAIFEX TX night backfill rows imported: {$imported}.");

        return self::SUCCESS;
    }

    private function monthlyChunks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $chunks = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $end = $cursor->addMonthNoOverflow()->subDay();
            if ($end->greaterThan($to)) {
                $end = $to;
            }

            $chunks[] = [$cursor, $end];
            $cursor = $end->addDay();
        }

        return $chunks;
    }

    private function fetchChunk(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $response = Http::asForm()
            ->timeout(60)
            ->retry(2, 1000)
            ->post(self::URL, [
                'down_type' => '1',
                'commodity_id' => 'TX',
                'commodity_id2' => '',
                'queryStartDate' => $from->format('Y/m/d'),
                'queryEndDate' => $to->format('Y/m/d'),
            ]);

        if (! $response->ok()) {
            $this->warn("TAIFEX download failed: HTTP {$response->status()}");

            return collect();
        }

        return $this->parseCsv($response->body());
    }

    private function parseCsv(string $body): Collection
    {
        $utf8 = mb_convert_encoding($body, 'UTF-8', 'BIG5,CP950,UTF-8');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $utf8);
        rewind($stream);

        $header = fgetcsv($stream);
        if (! is_array($header)) {
            fclose($stream);

            return collect();
        }

        $header = array_map(fn ($column) => trim((string) $column), $header);
        $rows = collect();

        while (($line = fgetcsv($stream)) !== false) {
            if (count(array_filter($line, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($line[$index] ?? ''));
            }

            $rows->push($row);
        }

        fclose($stream);

        return $rows;
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{8}$/', $value)) {
            return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }

        if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $value)) {
            return str_replace('/', '-', $value);
        }

        return null;
    }

    private function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-' || Str::upper($value) === 'NULL') {
            return null;
        }

        $value = str_replace([',', '%'], '', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function percent(mixed $value): ?float
    {
        return $this->decimal($value);
    }

    private function state(?float $changePct): string
    {
        return match (true) {
            $changePct === null => 'unknown',
            $changePct >= 0.3 => 'positive',
            $changePct <= -0.3 => 'weak',
            default => 'neutral',
        };
    }
}
