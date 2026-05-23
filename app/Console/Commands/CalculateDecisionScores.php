<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockScore;
use Illuminate\Console\Command;

class CalculateDecisionScores extends Command
{
    protected $signature = 'market:calculate-decision-scores {--symbol= : Calculate one stock symbol}';

    protected $description = 'Calculate chip score, total score, decision, and confidence from available modules.';

    public function handle(): int
    {
        $stocks = Stock::query()
            ->where('is_active', true)
            ->with(['latestChip', 'latestScore'])
            ->when($this->option('symbol'), fn ($query, $symbol) => $query->where('symbol', $symbol))
            ->orderBy('symbol')
            ->get();

        $calculated = 0;
        $skipped = 0;

        foreach ($stocks as $stock) {
            $score = $stock->latestScore;

            if (! $score) {
                $skipped++;
                continue;
            }

            $chipScore = $stock->latestChip ? $this->chipScore($stock) : null;
            $totalScore = $this->totalScore($score->technical_score, $chipScore, $score->fundamental_score);
            $confidence = $this->confidenceScore($score->technical_score, $chipScore, $score->fundamental_score);
            $decision = $this->decision($totalScore);
            $riskFlags = array_values(array_unique(array_merge(
                $score->risk_flags ?? [],
                $this->chipRiskFlags($chipScore, $stock->latestChip),
            )));

            $score->fill([
                'chip_score' => $chipScore,
                'total_score' => $totalScore,
                'confidence_score' => $confidence,
                'decision' => $decision,
                'risk_flags' => $riskFlags,
            ])->save();

            $calculated++;
        }

        $this->info('Decision scores calculated: '.$calculated);
        $this->line('Skipped without base score: '.$skipped);

        return self::SUCCESS;
    }

    private function chipScore(Stock $stock): int
    {
        $chip = $stock->latestChip;
        $latestPrice = $stock->dailyPrices()->latest('trade_date')->first();
        $volume = max(1, (int) ($latestPrice?->volume ?? 1));

        $foreignRatio = $chip->foreign_net_buy / $volume;
        $trustRatio = $chip->investment_trust_net_buy / $volume;
        $dealerRatio = $chip->dealer_net_buy / $volume;
        $institutionalRatio = $chip->institutional_net_buy / $volume;

        $score = 50;
        $score += $this->ratioPoints($foreignRatio, 22);
        $score += $this->ratioPoints($trustRatio, 18);
        $score += $this->ratioPoints($dealerRatio, 10);
        $score += $this->ratioPoints($institutionalRatio, 18);

        if ($chip->foreign_net_buy > 0 && $chip->investment_trust_net_buy > 0) {
            $score += 6;
        }

        if ($chip->foreign_net_buy < 0 && $chip->investment_trust_net_buy < 0) {
            $score -= 8;
        }

        return max(0, min(100, (int) round($score)));
    }

    private function ratioPoints(float $ratio, int $weight): float
    {
        $capped = max(-0.12, min(0.12, $ratio));

        return ($capped / 0.12) * $weight;
    }

    private function totalScore(?int $technicalScore, ?int $chipScore, ?int $fundamentalScore): int
    {
        $available = [];

        if ($technicalScore !== null) {
            $available[] = ['score' => $technicalScore, 'weight' => 0.45];
        }

        if ($chipScore !== null) {
            $available[] = ['score' => $chipScore, 'weight' => 0.30];
        }

        if ($fundamentalScore !== null) {
            $available[] = ['score' => $fundamentalScore, 'weight' => 0.25];
        }

        if ($available === []) {
            return 0;
        }

        $weightSum = array_sum(array_column($available, 'weight'));
        $weighted = array_sum(array_map(fn ($item) => $item['score'] * $item['weight'], $available));

        return (int) round($weighted / $weightSum);
    }

    private function confidenceScore(?int $technicalScore, ?int $chipScore, ?int $fundamentalScore): int
    {
        $modules = collect([$technicalScore, $chipScore, $fundamentalScore])->filter(fn ($score) => $score !== null)->values();
        $confidence = 45 + ($modules->count() * 12);

        if ($modules->count() >= 2 && $modules->max() - $modules->min() <= 18) {
            $confidence += 12;
        }

        return max(0, min(100, $confidence));
    }

    private function decision(int $score): string
    {
        return match (true) {
            $score >= 85 => '強力買進',
            $score >= 70 => '買進',
            $score >= 55 => '續抱',
            $score >= 40 => '減碼',
            default => '賣出',
        };
    }

    private function chipRiskFlags(?int $chipScore, mixed $chip): array
    {
        if (! $chip || $chipScore === null) {
            return [];
        }

        $flags = [];

        if ($chipScore < 40) {
            $flags[] = 'institutional_selling';
        }

        if ($chip->foreign_net_buy < 0 && $chip->investment_trust_net_buy < 0) {
            $flags[] = 'foreign_and_trust_selling';
        }

        return $flags;
    }
}
