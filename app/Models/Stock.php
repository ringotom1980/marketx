<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'market',
        'industry',
        'is_active',
        'listed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'listed_at' => 'date',
        ];
    }

    public function dailyPrices(): HasMany
    {
        return $this->hasMany(StockPrice1d::class);
    }

    public function latestScore(): HasOne
    {
        return $this->hasOne(StockScore::class)->latestOfMany('score_date');
    }

    public function chips(): HasMany
    {
        return $this->hasMany(StockChip1d::class);
    }

    public function latestChip(): HasOne
    {
        return $this->hasOne(StockChip1d::class)->latestOfMany('trade_date');
    }
}
