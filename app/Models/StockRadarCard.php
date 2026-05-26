<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRadarCard extends Model
{
    protected $fillable = [
        'card_date',
        'card_type',
        'stock_id',
        'rank',
        'confidence_score',
        'reasons',
        'metrics_payload',
    ];

    protected function casts(): array
    {
        return [
            'card_date' => 'date',
            'rank' => 'integer',
            'confidence_score' => 'integer',
            'reasons' => 'array',
            'metrics_payload' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
