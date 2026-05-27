<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_ai_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->string('title')->nullable();
            $table->text('summary');
            $table->json('data_pack')->nullable();
            $table->string('model')->nullable();
            $table->json('token_usage')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_ai_reports');
    }
};
