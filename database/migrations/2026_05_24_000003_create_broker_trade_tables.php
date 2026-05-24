<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_branches', function (Blueprint $table) {
            $table->id();
            $table->string('market', 16);
            $table->string('code', 32);
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['market', 'code']);
            $table->index('name');
        });

        Schema::create('stock_broker_trades_1d', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_branch_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->bigInteger('buy_volume')->default(0);
            $table->bigInteger('sell_volume')->default(0);
            $table->bigInteger('net_volume')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'broker_branch_id', 'trade_date'], 'stock_broker_trade_unique');
            $table->index(['trade_date', 'stock_id']);
            $table->index(['broker_branch_id', 'trade_date']);
        });

        Schema::create('broker_daytrade_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_branch_id')->constrained()->cascadeOnDelete();
            $table->date('buy_date');
            $table->date('sell_date');
            $table->bigInteger('buy_volume');
            $table->bigInteger('sell_volume');
            $table->decimal('sellback_ratio', 8, 4);
            $table->unsignedTinyInteger('confidence_score')->default(50);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'broker_branch_id', 'buy_date', 'sell_date'], 'broker_daytrade_unique');
            $table->index(['stock_id', 'buy_date']);
            $table->index(['stock_id', 'sell_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_daytrade_patterns');
        Schema::dropIfExists('stock_broker_trades_1d');
        Schema::dropIfExists('broker_branches');
    }
};
