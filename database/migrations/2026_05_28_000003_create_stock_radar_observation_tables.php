<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_radar_observations', function (Blueprint $table) {
            $table->id();
            $table->date('selected_date')->index();
            $table->string('card_type', 32)->index();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('entry_rank');
            $table->unsignedTinyInteger('entry_confidence')->default(0);
            $table->json('entry_reasons')->nullable();
            $table->json('entry_metrics')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->date('last_checked_date')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->json('performance_payload')->nullable();
            $table->timestamps();

            $table->unique(['selected_date', 'card_type', 'stock_id']);
            $table->index(['status', 'card_type']);
        });

        Schema::create('stock_radar_observation_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_radar_observation_id')->constrained('stock_radar_observations')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('check_date')->index();
            $table->unsignedSmallInteger('days_since_selected')->default(0);
            $table->decimal('close', 12, 4)->nullable();
            $table->decimal('change', 12, 4)->nullable();
            $table->decimal('change_pct', 12, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->boolean('condition_still_present')->default(false)->index();
            $table->json('check_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_radar_observation_id', 'check_date']);
            $table->index(['stock_id', 'check_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_radar_observation_checks');
        Schema::dropIfExists('stock_radar_observations');
    }
};
