<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->string('knowledge_type', 40)->index();
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->string('source_name')->nullable();
            $table->text('source_url')->nullable();
            $table->date('knowledge_date')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('category', 80)->nullable()->index();
            $table->string('region', 80)->nullable()->index();
            $table->string('sentiment', 32)->nullable()->index();
            $table->unsignedTinyInteger('importance_score')->default(50)->index();
            $table->unsignedTinyInteger('confidence_score')->default(70);
            $table->json('themes')->nullable();
            $table->json('industries')->nullable();
            $table->json('symbols')->nullable();
            $table->json('keywords')->nullable();
            $table->json('evidence_payload')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_type', 'source_type', 'source_id'], 'knowledge_source_unique');
            $table->index(['knowledge_type', 'knowledge_date', 'importance_score'], 'knowledge_type_date_score_idx');
        });

        Schema::create('language_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_type', 40)->index();
            $table->string('section', 40)->nullable()->index();
            $table->string('tone', 24)->default('neutral')->index();
            $table->string('condition_key', 80)->nullable()->index();
            $table->text('text');
            $table->unsignedSmallInteger('weight')->default(50);
            $table->string('source', 40)->default('manual')->index();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['asset_type', 'section', 'condition_key', 'status'], 'language_asset_lookup_idx');
        });

        Schema::create('paragraph_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 100)->unique();
            $table->string('name');
            $table->string('section', 40)->index();
            $table->string('scenario', 80)->nullable()->index();
            $table->string('tone', 24)->default('neutral')->index();
            $table->text('body_template');
            $table->json('required_conditions')->nullable();
            $table->json('optional_conditions')->nullable();
            $table->unsignedSmallInteger('weight')->default(50);
            $table->string('source', 40)->default('manual');
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['section', 'scenario', 'status']);
        });

        Schema::create('article_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 100)->unique();
            $table->string('name');
            $table->string('scenario', 80)->index();
            $table->string('tone', 24)->default('neutral')->index();
            $table->json('section_order');
            $table->text('opening_template')->nullable();
            $table->text('closing_template')->nullable();
            $table->json('style_rules')->nullable();
            $table->json('selection_rules')->nullable();
            $table->unsignedSmallInteger('weight')->default(50);
            $table->string('source', 40)->default('manual');
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_learning_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_role_id')->nullable()->constrained('agent_roles')->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('suggestion_type', 48)->index();
            $table->string('target_table', 80)->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->string('title');
            $table->text('rationale')->nullable();
            $table->json('proposed_payload');
            $table->json('evidence_payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'suggestion_type'], 'learning_suggestion_queue_idx');
        });

        Schema::create('agent_learning_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_learning_suggestion_id')->constrained('agent_learning_suggestions')->cascadeOnDelete();
            $table->string('published_table', 80);
            $table->unsignedBigInteger('published_id')->nullable();
            $table->string('status', 24)->default('published')->index();
            $table->timestamp('published_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_learning_publications');
        Schema::dropIfExists('agent_learning_suggestions');
        Schema::dropIfExists('article_templates');
        Schema::dropIfExists('paragraph_templates');
        Schema::dropIfExists('language_assets');
        Schema::dropIfExists('market_knowledge_items');
    }
};
