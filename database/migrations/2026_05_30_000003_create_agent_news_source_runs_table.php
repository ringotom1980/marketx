<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_news_source_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_name')->index();
            $table->string('source_type', 60)->index();
            $table->string('category', 80)->nullable()->index();
            $table->string('status', 32)->default('success')->index();
            $table->timestamp('fetched_at')->nullable()->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_name', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_news_source_runs');
    }
};
