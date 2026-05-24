<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Support\Ai\AiPipelineService;
use App\Support\Ai\StockDataPackBuilder;
use Illuminate\Console\Command;

class AiStatus extends Command
{
    protected $signature = 'market:ai-status {--symbol= : Show a sample stock data pack}';

    protected $description = 'Show MarketX AI v3 pipeline configuration without calling external AI APIs.';

    public function handle(AiPipelineService $pipeline, StockDataPackBuilder $builder): int
    {
        $status = $pipeline->status();

        $this->info('MarketX AI Pipeline');
        $this->line('Enabled: '.($status['enabled'] ? 'yes' : 'no'));
        $this->line('Gemini: '.($status['gemini_configured'] ? 'configured' : 'missing').' / '.$status['gemini_model']);
        $this->line('Groq: '.($status['groq_configured'] ? 'configured' : 'missing').' / '.$status['groq_model']);

        foreach ($status['limits'] as $task => $limit) {
            $this->line($task.': '.$limit['remaining'].' / '.$limit['limit'].' remaining today');
        }

        if ($this->option('symbol')) {
            $stock = Stock::query()
                ->with(['latestScore', 'latestChip'])
                ->where('symbol', $this->option('symbol'))
                ->firstOrFail();

            $this->newLine();
            $this->line(json_encode($builder->build($stock), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
