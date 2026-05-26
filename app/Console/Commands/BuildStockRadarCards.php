<?php

namespace App\Console\Commands;

use App\Support\StockRadarCardBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildStockRadarCards extends Command
{
    protected $signature = 'market:build-stock-radar-cards {--date= : Card date YYYY-MM-DD} {--limit=6 : Items per card}';

    protected $description = 'Precompute homepage stock radar cards from all active Taiwan stocks.';

    public function handle(StockRadarCardBuilder $builder): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $builder->build($this->option('date'), $limit);
        $cardDate = $result['card_date'];
        unset($result['card_date']);

        DB::transaction(function () use ($result, $cardDate) {
            DB::table('stock_radar_cards')
                ->where('card_date', $cardDate)
                ->delete();

            $now = now();
            $rows = [];

            foreach ($result as $type => $items) {
                foreach ($items->values() as $index => $item) {
                    $rows[] = [
                        'card_date' => $cardDate,
                        'card_type' => $type,
                        'stock_id' => $item['stock_id'],
                        'rank' => $index + 1,
                        'confidence_score' => $item['confidence'],
                        'reasons' => json_encode($item['reasons'], JSON_UNESCAPED_UNICODE),
                        'metrics_payload' => json_encode($item['metrics'], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('stock_radar_cards')->insert($rows);
            }
        });

        $this->info('Stock radar cards built for '.$cardDate);

        foreach ($result as $type => $items) {
            $this->line($type.': '.$items->count());
        }

        return self::SUCCESS;
    }
}
