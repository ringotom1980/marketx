<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'source', 'ai_status', 'ai_payload', 'is_active'];

    protected function casts(): array
    {
        return [
            'ai_payload' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function stocks(): BelongsToMany
    {
        return $this->belongsToMany(Stock::class, 'stock_theme_map')->withPivot(['weight', 'reason'])->withTimestamps();
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ThemeScore::class);
    }
}
