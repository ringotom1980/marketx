<?php

namespace App\Console\Commands;

use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateFundamentalScores extends Command
{
    protected $signature = 'market:calculate-fundamental-scores';

    protected $description = 'Calculate fundamental scores when financial and revenue data are available.';

    public function handle(): int
    {
        $calculated = 0;
        $skipped = 0;

        Stock::query()->where('is_active', true)->with('latestScore')->chunkById(500, function ($stocks) use (&$calculated, &$skipped) {
            foreach ($stocks as $stock) {
                $latestFinancial = DB::table('stock_financials')->where('stock_id', $stock->id)->orderByDesc('period')->first();
                $latestRevenue = DB::table('stock_revenues')->where('stock_id', $stock->id)->orderByDesc('year_month')->first();

                if (! $latestFinancial && ! $latestRevenue) {
                    $skipped++;
                    continue;
                }

                $score = 50;

                if ($latestFinancial?->roe !== null) {
                    $score += min(20, max(-10, ((float) $latestFinancial->roe - 8) * 1.2));
                }

                if ($latestFinancial?->gross_margin !== null) {
                    $score += min(15, max(-10, ((float) $latestFinancial->gross_margin - 20) * 0.4));
                }

                if ($latestRevenue?->yoy_pct !== null) {
                    $score += min(20, max(-20, ((float) $latestRevenue->yoy_pct) * 0.5));
                }

                if ($latestFinancial?->per !== null) {
                    $per = (float) $latestFinancial->per;

                    if ($per > 0 && $per <= 12) {
                        $score += 8;
                    } elseif ($per > 25 && $per <= 40) {
                        $score -= 5;
                    } elseif ($per > 40) {
                        $score -= 10;
                    }
                }

                if ($latestFinancial?->pb_ratio !== null) {
                    $pb = (float) $latestFinancial->pb_ratio;

                    if ($pb > 0 && $pb <= 1.2) {
                        $score += 5;
                    } elseif ($pb > 4) {
                        $score -= 6;
                    }
                }

                if ($latestFinancial?->dividend_yield !== null) {
                    $yield = (float) $latestFinancial->dividend_yield;

                    if ($yield >= 4) {
                        $score += 4;
                    } elseif ($yield > 0 && $yield < 1) {
                        $score -= 2;
                    }
                }

                $score = max(0, min(100, (int) round($score)));
                $base = $stock->latestScore;

                if ($base) {
                    $base->fundamental_score = $score;
                    $base->save();
                    $calculated++;
                }
            }
        });

        $this->info('Fundamental scores calculated: '.$calculated);
        $this->line('Skipped without financial data: '.$skipped);

        return self::SUCCESS;
    }
}
