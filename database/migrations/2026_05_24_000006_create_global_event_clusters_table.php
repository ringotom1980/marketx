<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_event_clusters', function (Blueprint $table) {
            $table->id();
            $table->date('cluster_date');
            $table->string('cluster_key', 160);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->unsignedTinyInteger('importance_score')->default(50);
            $table->string('sentiment', 32)->nullable();
            $table->json('themes')->nullable();
            $table->json('industries')->nullable();
            $table->json('related_symbols')->nullable();
            $table->json('event_ids')->nullable();
            $table->json('ai_payload')->nullable();
            $table->string('ai_status', 32)->nullable();
            $table->timestamps();

            $table->unique(['cluster_date', 'cluster_key']);
            $table->index(['cluster_date', 'importance_score']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_event_clusters');
    }
};
