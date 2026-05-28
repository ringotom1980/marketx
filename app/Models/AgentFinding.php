<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentFinding extends Model
{
    protected $fillable = [
        'agent_role_id',
        'agent_run_id',
        'status',
        'severity',
        'finding_type',
        'page',
        'symbol',
        'theme_slug',
        'title',
        'description',
        'evidence',
        'recommendation',
        'codex_feedback',
        'reviewed_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentRole::class, 'agent_role_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function memories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }
}
