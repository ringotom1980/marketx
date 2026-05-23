<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPrice1d extends Model
{
    use HasFactory;

    protected $table = 'stock_prices_1d';

    protected $fillable = [
        'stock_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'change',
        'change_pct',
        'volume',
        'turnover',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'open' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'close' => 'decimal:4',
            'change' => 'decimal:4',
            'change_pct' => 'decimal:4',
            'volume' => 'integer',
            'turnover' => 'integer',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

