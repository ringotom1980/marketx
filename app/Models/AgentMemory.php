<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemory extends Model
{
    protected $fillable = [
        'agent_role_id',
        'agent_finding_id',
        'memory_type',
        'status',
        'title',
        'rule_summary',
        'correct_pattern',
        'wrong_pattern',
        'codex_feedback',
        'confidence',
        'usage_count',
        'last_used_at',
        'examples',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'examples' => 'array',
            'payload' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentRole::class, 'agent_role_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AgentFinding::class, 'agent_finding_id');
    }
}
