<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('source_name')->nullable()->index();
            $table->string('source_type', 40)->default('rss')->index();
            $table->text('url')->nullable();
            $table->string('url_hash', 64)->unique();
            $table->timestamp('published_at')->nullable()->index();
            $table->date('news_date')->nullable()->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('language', 16)->default('zh-TW')->index();
            $table->string('region', 80)->nullable()->index();
            $table->string('category', 80)->nullable()->index();
            $table->string('sentiment', 32)->nullable()->index();
            $table->unsignedTinyInteger('importance_score')->default(50)->index();
            $table->json('themes')->nullable();
            $table->json('industries')->nullable();
            $table->json('symbols')->nullable();
            $table->json('keywords')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('market_events', function (Blueprint $table) {
            $table->id();
            $table->date('event_date')->index();
            $table->string('event_key', 160)->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('category', 80)->nullable()->index();
            $table->string('region', 80)->nullable()->index();
            $table->string('sentiment', 32)->nullable()->index();
            $table->unsignedTinyInteger('importance_score')->default(50)->index();
            $table->json('themes')->nullable();
            $table->json('industries')->nullable();
            $table->json('symbols')->nullable();
            $table->json('source_news_ids')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('theme_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->string('theme_name')->index();
            $table->string('theme_slug')->nullable()->index();
            $table->text('definition')->nullable();
            $table->text('bullish_drivers')->nullable();
            $table->text('risk_drivers')->nullable();
            $table->json('keywords')->nullable();
            $table->json('representative_symbols')->nullable();
            $table->json('latest_metrics')->nullable();
            $table->date('asof_date')->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->unique(['theme_name']);
        });

        Schema::create('industry_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('industry_name')->unique();
            $table->text('definition')->nullable();
            $table->text('supply_chain_notes')->nullable();
            $table->text('bullish_drivers')->nullable();
            $table->text('risk_drivers')->nullable();
            $table->json('representative_symbols')->nullable();
            $table->json('related_themes')->nullable();
            $table->json('latest_metrics')->nullable();
            $table->date('asof_date')->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('historical_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_key', 160)->unique();
            $table->string('case_type', 80)->index();
            $table->string('title');
            $table->text('background')->nullable();
            $table->text('trigger_event')->nullable();
            $table->text('market_reaction')->nullable();
            $table->text('lesson')->nullable();
            $table->date('case_date')->nullable()->index();
            $table->json('themes')->nullable();
            $table->json('industries')->nullable();
            $table->json('symbols')->nullable();
            $table->json('metrics')->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(70);
            $table->string('source', 40)->default('system');
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_cases');
        Schema::dropIfExists('industry_knowledge');
        Schema::dropIfExists('theme_knowledge');
        Schema::dropIfExists('market_events');
        Schema::dropIfExists('news_items');
    }
};
