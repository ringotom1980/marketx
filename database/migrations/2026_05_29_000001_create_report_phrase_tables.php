<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_phrases', function (Blueprint $table) {
            $table->id();
            $table->string('section', 40)->index();
            $table->string('tone', 24)->default('neutral')->index();
            $table->string('condition_key', 80)->nullable()->index();
            $table->text('template');
            $table->unsignedSmallInteger('weight')->default(50);
            $table->string('source', 40)->default('manual');
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['section', 'condition_key', 'status']);
        });

        Schema::create('report_phrase_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_role_id')->nullable()->constrained('agent_roles')->nullOnDelete();
            $table->string('section', 40)->index();
            $table->string('tone', 24)->default('neutral')->index();
            $table->string('condition_key', 80)->nullable()->index();
            $table->text('template');
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('approved_phrase_id')->nullable()->constrained('report_phrases')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_phrase_suggestions');
        Schema::dropIfExists('report_phrases');
    }
};
