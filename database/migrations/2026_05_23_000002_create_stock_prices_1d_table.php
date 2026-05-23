<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_prices_1d', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('close', 12, 4)->nullable();
            $table->decimal('change', 12, 4)->nullable();
            $table->decimal('change_pct', 8, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->unsignedBigInteger('turnover')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prices_1d');
    }
};

