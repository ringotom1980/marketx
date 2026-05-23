<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_margins_1d', function (Blueprint $table) {
            $table->id();
            $table->string('market', 16);
            $table->date('trade_date');
            $table->bigInteger('margin_buy')->nullable();
            $table->bigInteger('margin_sell')->nullable();
            $table->bigInteger('margin_cash_repayment')->nullable();
            $table->bigInteger('margin_previous_balance')->nullable();
            $table->bigInteger('margin_balance')->nullable();
            $table->bigInteger('short_sell')->nullable();
            $table->bigInteger('short_buy')->nullable();
            $table->bigInteger('short_repayment')->nullable();
            $table->bigInteger('short_previous_balance')->nullable();
            $table->bigInteger('short_balance')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['market', 'trade_date']);
            $table->index('trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_margins_1d');
    }
};
