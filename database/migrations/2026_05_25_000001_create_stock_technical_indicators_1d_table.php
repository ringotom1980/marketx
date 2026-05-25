<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_technical_indicators_1d', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('close', 12, 4)->nullable();
            $table->decimal('sma5', 12, 4)->nullable();
            $table->decimal('sma10', 12, 4)->nullable();
            $table->decimal('sma20', 12, 4)->nullable();
            $table->decimal('sma60', 12, 4)->nullable();
            $table->decimal('sma120', 12, 4)->nullable();
            $table->decimal('sma240', 12, 4)->nullable();
            $table->decimal('ema12', 12, 4)->nullable();
            $table->decimal('ema26', 12, 4)->nullable();
            $table->decimal('rsi6', 12, 4)->nullable();
            $table->decimal('rsi12', 12, 4)->nullable();
            $table->decimal('rsi14', 12, 4)->nullable();
            $table->decimal('macd', 12, 4)->nullable();
            $table->decimal('macd_signal', 12, 4)->nullable();
            $table->decimal('macd_histogram', 12, 4)->nullable();
            $table->decimal('macd_previous', 12, 4)->nullable();
            $table->decimal('macd_signal_previous', 12, 4)->nullable();
            $table->decimal('macd_histogram_previous', 12, 4)->nullable();
            $table->decimal('k9', 12, 4)->nullable();
            $table->decimal('d9', 12, 4)->nullable();
            $table->decimal('k9_previous', 12, 4)->nullable();
            $table->decimal('d9_previous', 12, 4)->nullable();
            $table->decimal('bollinger_upper20', 12, 4)->nullable();
            $table->decimal('bollinger_middle20', 12, 4)->nullable();
            $table->decimal('bollinger_lower20', 12, 4)->nullable();
            $table->decimal('atr14', 12, 4)->nullable();
            $table->decimal('bais5', 12, 4)->nullable();
            $table->decimal('bais10', 12, 4)->nullable();
            $table->decimal('bais20', 12, 4)->nullable();
            $table->decimal('bais60', 12, 4)->nullable();
            $table->decimal('return5', 12, 4)->nullable();
            $table->decimal('return10', 12, 4)->nullable();
            $table->decimal('return20', 12, 4)->nullable();
            $table->decimal('return60', 12, 4)->nullable();
            $table->decimal('volume_ratio5', 12, 4)->nullable();
            $table->decimal('volume_ratio20', 12, 4)->nullable();
            $table->decimal('volatility20', 12, 4)->nullable();
            $table->decimal('support20', 12, 4)->nullable();
            $table->decimal('resistance20', 12, 4)->nullable();
            $table->boolean('breakout20')->default(false);
            $table->unsignedTinyInteger('technical_score')->nullable();
            $table->json('signals')->nullable();
            $table->json('risk_flags')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
            $table->index('technical_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_technical_indicators_1d');
    }
};
