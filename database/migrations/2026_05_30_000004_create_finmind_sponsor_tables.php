<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->dateTime('snapshot_at')->index();
            $table->decimal('open', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('close', 12, 4)->nullable();
            $table->decimal('change_price', 12, 4)->nullable();
            $table->decimal('change_rate', 10, 4)->nullable();
            $table->decimal('average_price', 12, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->unsignedBigInteger('total_volume')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedBigInteger('total_amount')->nullable();
            $table->decimal('buy_price', 12, 4)->nullable();
            $table->unsignedBigInteger('buy_volume')->nullable();
            $table->decimal('sell_price', 12, 4)->nullable();
            $table->unsignedBigInteger('sell_volume')->nullable();
            $table->decimal('volume_ratio', 10, 4)->nullable();
            $table->unsignedBigInteger('yesterday_volume')->nullable();
            $table->string('tick_type', 16)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'snapshot_at']);
        });

        Schema::create('stock_kbars_1m', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->time('minute')->index();
            $table->decimal('open', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('close', 12, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'minute']);
        });

        Schema::create('stock_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->time('trade_time')->index();
            $table->decimal('deal_price', 12, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->string('tick_type', 16)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'trade_time', 'deal_price'], 'stock_tick_unique');
        });

        Schema::create('stock_broker_trade_sec_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->bigInteger('buy_volume')->default(0);
            $table->bigInteger('sell_volume')->default(0);
            $table->decimal('buy_price', 12, 4)->nullable();
            $table->decimal('sell_price', 12, 4)->nullable();
            $table->bigInteger('net_volume')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'broker_branch_id', 'trade_date'], 'broker_sec_agg_unique');
        });

        Schema::create('government_bank_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->string('bank_name', 80)->index();
            $table->bigInteger('buy_volume')->default(0);
            $table->bigInteger('sell_volume')->default(0);
            $table->bigInteger('net_volume')->default(0);
            $table->bigInteger('buy_amount')->default(0);
            $table->bigInteger('sell_amount')->default(0);
            $table->bigInteger('net_amount')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'bank_name']);
        });

        Schema::create('stock_block_trade_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->string('trade_type', 64)->nullable()->index();
            $table->decimal('price', 12, 4)->nullable();
            $table->bigInteger('buy_volume')->default(0);
            $table->bigInteger('sell_volume')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'broker_branch_id', 'trade_type', 'price'], 'block_report_unique');
        });

        Schema::create('stock_block_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->string('trade_type', 64)->nullable()->index();
            $table->decimal('price', 12, 4)->nullable();
            $table->bigInteger('volume')->default(0);
            $table->bigInteger('trading_money')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'trade_type', 'price'], 'block_trade_unique');
        });

        Schema::create('stock_holding_shares_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->string('holding_level', 64)->index();
            $table->unsignedBigInteger('people')->nullable();
            $table->decimal('percent', 10, 4)->nullable();
            $table->string('unit', 32)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date', 'holding_level']);
        });

        Schema::create('stock_market_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 16)->index();
            $table->date('trade_date')->index();
            $table->bigInteger('market_value')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->decimal('weight_per', 10, 4)->nullable();
            $table->string('market_type', 32)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'trade_date']);
        });

        Schema::create('market_margin_maintenance', function (Blueprint $table) {
            $table->id();
            $table->date('trade_date')->unique();
            $table->decimal('total_exchange_margin_maintenance', 10, 4)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_margin_maintenance');
        Schema::dropIfExists('stock_market_values');
        Schema::dropIfExists('stock_holding_shares_levels');
        Schema::dropIfExists('stock_block_trades');
        Schema::dropIfExists('stock_block_trade_reports');
        Schema::dropIfExists('government_bank_trades');
        Schema::dropIfExists('stock_broker_trade_sec_aggregates');
        Schema::dropIfExists('stock_ticks');
        Schema::dropIfExists('stock_kbars_1m');
        Schema::dropIfExists('stock_snapshots');
    }
};
