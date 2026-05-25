<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTechnicalIndicator1d extends Model
{
    use HasFactory;

    protected $table = 'stock_technical_indicators_1d';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'breakout20' => 'boolean',
            'signals' => 'array',
            'risk_flags' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
