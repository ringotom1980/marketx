<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_radar_cards', function (Blueprint $table) {
            $table->id();
            $table->date('card_date');
            $table->string('card_type', 32);
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->json('reasons')->nullable();
            $table->json('metrics_payload')->nullable();
            $table->timestamps();

            $table->unique(['card_date', 'card_type', 'stock_id']);
            $table->index(['card_date', 'card_type', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_radar_cards');
    }
};
