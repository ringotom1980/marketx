<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $fillable = [
        'agent_role_id',
        'run_key',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'findings_count',
        'memories_count',
        'summary',
        'error_message',
        'input_context',
        'output_context',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'input_context' => 'array',
            'output_context' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentRole::class, 'agent_role_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AgentFinding::class);
    }
}
