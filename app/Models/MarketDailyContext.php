<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketDailyContext extends Model
{
    protected $fillable = [
        'context_date',
        'session',
        'market_phase',
        'risk_score',
        'opportunity_score',
        'summary',
        'global_markets',
        'taiwan_market',
        'theme_snapshot',
        'radar_snapshot',
        'event_snapshot',
        'ai_reports',
        'freshness',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'context_date' => 'date',
            'risk_score' => 'integer',
            'opportunity_score' => 'integer',
            'global_markets' => 'array',
            'taiwan_market' => 'array',
            'theme_snapshot' => 'array',
            'radar_snapshot' => 'array',
            'event_snapshot' => 'array',
            'ai_reports' => 'array',
            'freshness' => 'array',
            'payload' => 'array',
        ];
    }
}
