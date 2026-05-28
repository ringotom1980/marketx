<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_daily_contexts', function (Blueprint $table) {
            $table->id();
            $table->date('context_date');
            $table->string('session', 32)->default('daily');
            $table->string('market_phase', 64)->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->unsignedTinyInteger('opportunity_score')->nullable();
            $table->text('summary')->nullable();
            $table->json('global_markets')->nullable();
            $table->json('taiwan_market')->nullable();
            $table->json('theme_snapshot')->nullable();
            $table->json('radar_snapshot')->nullable();
            $table->json('event_snapshot')->nullable();
            $table->json('ai_reports')->nullable();
            $table->json('freshness')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['context_date', 'session']);
            $table->index('context_date');
            $table->index('market_phase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_daily_contexts');
    }
};
