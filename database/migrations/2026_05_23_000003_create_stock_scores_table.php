<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('score_date');
            $table->unsignedTinyInteger('macro_score')->nullable();
            $table->unsignedTinyInteger('event_score')->nullable();
            $table->unsignedTinyInteger('theme_score')->nullable();
            $table->unsignedTinyInteger('technical_score')->nullable();
            $table->unsignedTinyInteger('chip_score')->nullable();
            $table->unsignedTinyInteger('fundamental_score')->nullable();
            $table->unsignedTinyInteger('sentiment_score')->nullable();
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->string('decision', 32)->nullable();
            $table->json('technical_payload')->nullable();
            $table->json('risk_flags')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'score_date']);
            $table->index('score_date');
            $table->index('technical_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_scores');
    }
};

