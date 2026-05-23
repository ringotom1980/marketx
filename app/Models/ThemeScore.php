<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeScore extends Model
{
    protected $fillable = [
        'theme_id',
        'score_date',
        'heat_score',
        'news_score',
        'price_score',
        'volume_score',
        'chip_score',
        'ai_event_score',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'score_date' => 'date',
            'payload' => 'array',
        ];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}

