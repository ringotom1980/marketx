<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRole extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'scope',
        'mission',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AgentFinding::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }
}
