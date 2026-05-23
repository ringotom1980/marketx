<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_chips_1d', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->bigInteger('foreign_net_buy')->nullable();
            $table->bigInteger('investment_trust_net_buy')->nullable();
            $table->bigInteger('dealer_net_buy')->nullable();
            $table->bigInteger('institutional_net_buy')->nullable();
            $table->bigInteger('margin_balance')->nullable();
            $table->bigInteger('short_balance')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_chips_1d');
    }
};

