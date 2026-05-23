<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('period', 16);
            $table->decimal('eps', 12, 4)->nullable();
            $table->decimal('roe', 12, 4)->nullable();
            $table->decimal('gross_margin', 12, 4)->nullable();
            $table->decimal('operating_margin', 12, 4)->nullable();
            $table->decimal('per', 12, 4)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'period']);
        });

        Schema::create('stock_revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->bigInteger('revenue')->nullable();
            $table->decimal('mom_pct', 12, 4)->nullable();
            $table->decimal('yoy_pct', 12, 4)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'year_month']);
        });

        Schema::create('global_market_data', function (Blueprint $table) {
            $table->id();
            $table->string('indicator', 64);
            $table->date('trade_date');
            $table->decimal('value', 18, 6)->nullable();
            $table->decimal('change', 18, 6)->nullable();
            $table->decimal('change_pct', 12, 6)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('source')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['indicator', 'trade_date']);
        });

        Schema::create('global_events', function (Blueprint $table) {
            $table->id();
            $table->dateTime('event_date')->nullable();
            $table->string('source')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('impact_direction', 32)->nullable();
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('theme_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();
            $table->date('score_date');
            $table->unsignedTinyInteger('heat_score')->nullable();
            $table->unsignedTinyInteger('news_score')->nullable();
            $table->unsignedTinyInteger('price_score')->nullable();
            $table->unsignedTinyInteger('volume_score')->nullable();
            $table->unsignedTinyInteger('chip_score')->nullable();
            $table->unsignedTinyInteger('ai_event_score')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['theme_id', 'score_date']);
        });

        Schema::create('stock_theme_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weight')->default(50);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'theme_id']);
        });

        Schema::create('stock_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->string('decision', 32)->nullable();
            $table->text('summary')->nullable();
            $table->text('bull_case')->nullable();
            $table->text('bear_case')->nullable();
            $table->text('risk_summary')->nullable();
            $table->json('data_pack')->nullable();
            $table->string('model')->nullable();
            $table->json('token_usage')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'report_date']);
        });

        Schema::create('watchlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'stock_id']);
        });

        Schema::create('system_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name');
            $table->string('status', 32);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 32);
            $table->string('source')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_logs', function (Blueprint $table) {
            $table->id();
            $table->string('task');
            $table->string('model')->nullable();
            $table->string('input_hash')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->decimal('cost_estimate', 12, 6)->nullable();
            $table->string('status', 32);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
        Schema::dropIfExists('system_logs');
        Schema::dropIfExists('system_jobs');
        Schema::dropIfExists('watchlist');
        Schema::dropIfExists('stock_reports');
        Schema::dropIfExists('stock_theme_map');
        Schema::dropIfExists('theme_scores');
        Schema::dropIfExists('themes');
        Schema::dropIfExists('global_events');
        Schema::dropIfExists('global_market_data');
        Schema::dropIfExists('stock_revenues');
        Schema::dropIfExists('stock_financials');
    }
};

