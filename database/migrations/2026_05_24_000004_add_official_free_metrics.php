<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_chips_1d', function (Blueprint $table) {
            $table->boolean('day_trade_eligible')->nullable()->after('short_balance');
            $table->boolean('day_trade_suspended')->nullable()->after('day_trade_eligible');
            $table->bigInteger('lending_available_volume')->nullable()->after('day_trade_suspended');
            $table->bigInteger('foreign_available_shares')->nullable()->after('lending_available_volume');
            $table->bigInteger('foreign_held_shares')->nullable()->after('foreign_available_shares');
            $table->decimal('foreign_available_ratio', 12, 4)->nullable()->after('foreign_held_shares');
            $table->decimal('foreign_held_ratio', 12, 4)->nullable()->after('foreign_available_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('stock_chips_1d', function (Blueprint $table) {
            $table->dropColumn([
                'day_trade_eligible',
                'day_trade_suspended',
                'lending_available_volume',
                'foreign_available_shares',
                'foreign_held_shares',
                'foreign_available_ratio',
                'foreign_held_ratio',
            ]);
        });
    }
};
