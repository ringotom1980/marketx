<?php

namespace App\Console\Commands;

use App\Models\AgentFinding;
use Illuminate\Console\Command;

class MarkAgentFindingStatus extends Command
{
    protected $signature = 'market:agents-mark-finding
        {id : Agent finding id}
        {status : New status: pending, observing, accepted, rejected, resolved}
        {--feedback= : Codex feedback or handling note}';

    protected $description = 'Update one agent finding status through Laravel.';

    public function handle(): int
    {
        $status = (string) $this->argument('status');

        if (! in_array($status, ['pending', 'observing', 'accepted', 'rejected', 'resolved'], true)) {
            $this->error('Invalid status: '.$status);

            return self::FAILURE;
        }

        $finding = AgentFinding::query()->find((int) $this->argument('id'));

        if (! $finding) {
            $this->error('Agent finding not found.');

            return self::FAILURE;
        }

        $finding->update([
            'status' => $status,
            'codex_feedback' => trim((string) $this->option('feedback')) ?: $finding->codex_feedback,
            'reviewed_at' => in_array($status, ['accepted', 'rejected', 'resolved', 'observing'], true) ? now() : null,
        ]);

        $this->info("Agent finding {$finding->id} marked as {$status}.");

        return self::SUCCESS;
    }
}
