<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}

