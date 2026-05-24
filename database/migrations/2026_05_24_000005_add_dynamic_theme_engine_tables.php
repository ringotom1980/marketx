<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->string('source', 32)->default('seed')->after('description');
            $table->string('ai_status', 32)->nullable()->after('source');
            $table->json('ai_payload')->nullable()->after('ai_status');
        });

        Schema::create('theme_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keyword');
            $table->unsignedTinyInteger('weight')->default(50);
            $table->string('source', 32)->default('seed');
            $table->timestamps();

            $table->unique(['keyword', 'theme_id']);
            $table->index('keyword');
        });

        Schema::create('theme_event_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('global_event_id')->nullable()->constrained('global_events')->nullOnDelete();
            $table->string('keyword')->nullable();
            $table->unsignedTinyInteger('match_score')->default(50);
            $table->string('source', 32)->default('rule');
            $table->json('ai_prompt')->nullable();
            $table->json('ai_response')->nullable();
            $table->timestamps();

            $table->index(['theme_id', 'created_at']);
            $table->index('global_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_event_matches');
        Schema::dropIfExists('theme_keywords');

        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn(['source', 'ai_status', 'ai_payload']);
        });
    }
};
