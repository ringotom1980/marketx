<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = ['level', 'source', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}

