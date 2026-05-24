<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_financials', function (Blueprint $table) {
            $table->decimal('dividend_yield', 12, 4)->nullable()->after('per');
            $table->decimal('pb_ratio', 12, 4)->nullable()->after('dividend_yield');
        });
    }

    public function down(): void
    {
        Schema::table('stock_financials', function (Blueprint $table) {
            $table->dropColumn(['dividend_yield', 'pb_ratio']);
        });
    }
};
