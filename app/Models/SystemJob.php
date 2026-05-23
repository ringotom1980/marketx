<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemJob extends Model
{
    protected $fillable = [
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }
}

