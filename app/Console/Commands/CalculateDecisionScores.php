<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockScore;
use App\Support\ConfidenceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CalculateDecisionScores extends Command
{
    protected $signature = 'market:calculate-decision-scores {--symbol= : Calculate one stock symbol}';

    protected $description = 'Calculate chip score, total score, decision, and confidence from available modules.';

    public function handle(ConfidenceEngine $confidenceEngine): int
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
            $score->chip_score = $chipScore;
            $totalScore = $this->totalScore($score);
            $decision = $this->decision($totalScore);
            $riskFlags = array_values(array_unique(array_merge(
                $score->risk_flags ?? [],
                $this->chipRiskFlags($chipScore, $stock->latestChip),
            )));
            $confidence = $confidenceEngine->evaluate($stock, $score, $riskFlags);

            $score->fill([
                'chip_score' => $chipScore,
                'total_score' => $totalScore,
                'confidence_score' => $confidence['score'],
                'decision' => $decision,
                'risk_flags' => $confidence['risk_flags'],
                'confidence_payload' => $confidence['payload'],
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

    private function totalScore(StockScore $score): int
    {
        $available = [];

        foreach ([
            'technical_score' => 0.35,
            'chip_score' => 0.25,
            'fundamental_score' => 0.20,
            'macro_score' => 0.10,
            'event_score' => 0.05,
            'theme_score' => 0.05,
        ] as $field => $weight) {
            if ($score->{$field} !== null) {
                $available[] = ['score' => $score->{$field}, 'weight' => $weight];
            }
        }

        if ($available === []) {
            return 0;
        }

        $weightSum = array_sum(array_column($available, 'weight'));
        $weighted = array_sum(array_map(fn ($item) => $item['score'] * $item['weight'], $available));

        return (int) round($weighted / $weightSum);
    }

    private function confidenceScore(StockScore $score, int $totalScore, array $riskFlags, Stock $stock): int
    {
        $modules = collect([
            'technical' => $score->technical_score,
            'chip' => $score->chip_score,
            'fundamental' => $score->fundamental_score,
            'macro' => $score->macro_score,
            'event' => $score->event_score,
            'theme' => $score->theme_score,
        ])->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value);

        if ($modules->isEmpty()) {
            return 20;
        }

        $moduleCount = $modules->count();
        $coverage = ($moduleCount / 6) * 28;
        $average = (float) $modules->avg();
        $dispersion = $moduleCount >= 2 ? $this->standardDeviation($modules->values()->all()) : 30.0;
        $consistency = max(0, 22 - min(22, $dispersion * 0.9));
        $directionSupport = $this->directionSupport($modules->values()->all(), $totalScore);
        $boundary = $this->decisionBoundaryConfidence($totalScore);
        $freshness = $this->freshnessConfidence($stock, $score);
        $riskPenalty = min(18, count($riskFlags) * 4);

        $confidence = 24 + $coverage + $consistency + $directionSupport + $boundary + $freshness - $riskPenalty;

        $confidence += match (true) {
            $average >= 72 && $totalScore >= 70 => 3,
            $average <= 42 && $totalScore <= 45 => 3,
            default => 0,
        };

        return max(5, min(99, (int) round($confidence)));
    }

    private function standardDeviation(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $average = array_sum($values) / $count;
        $variance = array_sum(array_map(fn ($value) => ($value - $average) ** 2, $values)) / $count;

        return sqrt($variance);
    }

    private function directionSupport(array $values, int $totalScore): float
    {
        if ($values === []) {
            return 0.0;
        }

        if ($totalScore >= 70) {
            return (count(array_filter($values, fn ($value) => $value >= 60)) / count($values)) * 15;
        }

        if ($totalScore <= 45) {
            return (count(array_filter($values, fn ($value) => $value <= 50)) / count($values)) * 15;
        }

        return (count(array_filter($values, fn ($value) => $value >= 45 && $value <= 65)) / count($values)) * 10;
    }

    private function decisionBoundaryConfidence(int $totalScore): float
    {
        $boundaries = [40, 55, 70, 85];
        $distance = min(array_map(fn ($boundary) => abs($totalScore - $boundary), $boundaries));

        return min(12, $distance * 1.6);
    }

    private function freshnessConfidence(Stock $stock, StockScore $score): float
    {
        $latestPrice = $stock->dailyPrices()->latest('trade_date')->first();
        $dates = collect([
            $latestPrice?->trade_date,
            $stock->latestChip?->trade_date,
            $score->score_date,
        ])->filter();

        if ($dates->isEmpty()) {
            return 0.0;
        }

        $latest = $dates
            ->map(fn ($date) => CarbonImmutable::parse($date, 'Asia/Taipei'))
            ->max();
        $days = $latest->diffInDays(CarbonImmutable::now('Asia/Taipei'));

        return match (true) {
            $days <= 1 => 8,
            $days <= 3 => 5,
            $days <= 7 => 2,
            default => 0,
        };
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
