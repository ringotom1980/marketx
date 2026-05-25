<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'score_date',
        'macro_score',
        'event_score',
        'theme_score',
        'technical_score',
        'chip_score',
        'fundamental_score',
        'sentiment_score',
        'total_score',
        'confidence_score',
        'decision',
        'technical_payload',
        'risk_flags',
        'confidence_payload',
    ];

    protected function casts(): array
    {
        return [
            'score_date' => 'date',
            'technical_payload' => 'array',
            'risk_flags' => 'array',
            'confidence_payload' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
