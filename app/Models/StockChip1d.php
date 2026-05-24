<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockChip1d extends Model
{
    use HasFactory;

    protected $table = 'stock_chips_1d';

    protected $fillable = [
        'stock_id',
        'trade_date',
        'foreign_net_buy',
        'investment_trust_net_buy',
        'dealer_net_buy',
        'institutional_net_buy',
        'margin_balance',
        'short_balance',
        'day_trade_eligible',
        'day_trade_suspended',
        'lending_available_volume',
        'foreign_available_shares',
        'foreign_held_shares',
        'foreign_available_ratio',
        'foreign_held_ratio',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'day_trade_eligible' => 'boolean',
            'day_trade_suspended' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
