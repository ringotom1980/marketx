<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('scope')->nullable();
            $table->text('mission')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_role_id')->constrained('agent_roles')->cascadeOnDelete();
            $table->string('run_key')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedInteger('memories_count')->default(0);
            $table->text('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('input_context')->nullable();
            $table->json('output_context')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_role_id')->constrained('agent_roles')->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('severity')->default('info')->index();
            $table->string('finding_type')->index();
            $table->string('page')->nullable()->index();
            $table->string('symbol')->nullable()->index();
            $table->string('theme_slug')->nullable()->index();
            $table->string('title');
            $table->text('description');
            $table->text('evidence')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('codex_feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
        });

        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_role_id')->nullable()->constrained('agent_roles')->nullOnDelete();
            $table->foreignId('agent_finding_id')->nullable()->constrained('agent_findings')->nullOnDelete();
            $table->string('memory_type')->default('rule')->index();
            $table->string('status')->default('active')->index();
            $table->string('title');
            $table->text('rule_summary')->nullable();
            $table->text('correct_pattern')->nullable();
            $table->text('wrong_pattern')->nullable();
            $table->text('codex_feedback')->nullable();
            $table->unsignedInteger('confidence')->default(70);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('examples')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('agent_roles')->insert([
            [
                'slug' => 'data-quality',
                'name' => '資料品質員',
                'scope' => '資料完整度、更新時間、異常數值',
                'mission' => '檢查台股、夜盤、全球市場、籌碼、財務與題材資料是否缺漏、過期或異常，並提出可驗證的修正建議。',
                'settings' => json_encode(['priority' => 1], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'home-radar',
                'name' => '首頁分類員',
                'scope' => '首頁五張股票卡與題材熱度',
                'mission' => '複查優先觀察、風險升高、潛力觀察、低檔爆量、持續弱勢等分類是否符合技術、籌碼、財務與題材證據。',
                'settings' => json_encode(['priority' => 2], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'stock-consistency',
                'name' => '個股一致性員',
                'scope' => '個股頁評價、K 線、指標、AI 報告',
                'mission' => '檢查個股頁評價、信心指數、分類卡、技術說明、籌碼財務與 AI 報告是否互相矛盾。',
                'settings' => json_encode(['priority' => 3], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'theme-radar',
                'name' => '題材雷達員',
                'scope' => '題材熱度、代表股、升溫降溫',
                'mission' => '檢查題材熱度來源、代表股價格與籌碼是否支撐升溫或降溫判斷，並提醒題材庫需要新增或修正的項目。',
                'settings' => json_encode(['priority' => 4], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'global-radar',
                'name' => '全球雷達員',
                'scope' => '全球指數、ADR、匯率、利率、原物料',
                'mission' => '檢查全球雷達資料是否即時、完整，並確認盤前觀察是否有足夠市場依據。',
                'settings' => json_encode(['priority' => 5], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'learning-recorder',
                'name' => '學習紀錄員',
                'scope' => '規則記憶、錯誤案例、回饋紀錄',
                'mission' => '整理董事長與 Codex 確認過的規則、錯誤案例與修正原因，讓代理人隔天能依據新記憶複查。',
                'settings' => json_encode(['priority' => 6], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
        Schema::dropIfExists('agent_findings');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_roles');
    }
};
