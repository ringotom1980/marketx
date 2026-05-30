<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RunAgentLearningPipeline extends Command
{
    protected $signature = 'market:agents-learning-pipeline
        {--date= : Learning date, default today in Asia/Taipei}
        {--phase=all : all|collect|classify|language|rules|review}
        {--limit=80 : Maximum source rows per phase}
        {--publish-safe-defaults : Publish safe manual language assets and templates immediately}
        {--dry-run : Preview without writing}';

    protected $description = 'Run the MarketX multi-agent learning pipeline: collect, classify, suggest language assets, validate rules, and prepare Codex review cases.';

    private string $date;

    private int $limit;

    private bool $dryRun;

    public function handle(): int
    {
        if (! $this->requiredTablesExist()) {
            $this->error('Learning tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $this->date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Asia/Taipei')->toDateString()
            : CarbonImmutable::now('Asia/Taipei')->toDateString();
        $this->limit = max(10, min(500, (int) $this->option('limit')));
        $this->dryRun = (bool) $this->option('dry-run');

        $this->ensureAgentRoles();

        $phase = (string) $this->option('phase');
        $phases = $phase === 'all' ? ['collect', 'classify', 'language', 'rules', 'review'] : [$phase];

        foreach ($phases as $item) {
            match ($item) {
                'collect' => $this->runPhase('news-collector', 'collect', fn () => $this->collectKnowledge()),
                'classify' => $this->runPhase('knowledge-classifier', 'classify', fn () => $this->classifyKnowledge()),
                'language' => $this->runPhase('language-curator', 'language', fn () => $this->suggestLanguageAssets()),
                'rules' => $this->runPhase('rule-governor', 'rules', fn () => $this->inspectRules()),
                'review' => $this->runPhase('ollama-orchestrator', 'review', fn () => $this->prepareCodexReview()),
                default => throw new \InvalidArgumentException("Unknown phase: {$item}"),
            };
        }

        if ((bool) $this->option('publish-safe-defaults')) {
            $this->publishSafeDefaults();
        }

        $this->info('Agent learning pipeline completed.');

        return self::SUCCESS;
    }

    private function requiredTablesExist(): bool
    {
        foreach (['market_knowledge_items', 'language_assets', 'paragraph_templates', 'article_templates', 'agent_learning_suggestions'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function ensureAgentRoles(): void
    {
        $roles = [
            [
                'slug' => 'news-collector',
                'name' => '新聞收集員',
                'scope' => '全球新聞、台股新聞、財經文章、事件來源',
                'mission' => '每 6 小時收集可影響市場的新聞與文章，只負責保存來源與基本摘要，不直接下結論。',
                'settings' => ['priority' => 10, 'phase' => 'collect'],
            ],
            [
                'slug' => 'knowledge-classifier',
                'name' => '資料分類員',
                'scope' => '新聞、事件、題材、產業、歷史案例',
                'mission' => '把原始資料分類成可被報告引用的事件、題材、產業與案例，並清理過期資料。',
                'settings' => ['priority' => 20, 'phase' => 'classify'],
            ],
            [
                'slug' => 'language-curator',
                'name' => '語句學習員',
                'scope' => '語句庫、片語庫、段落庫、文章模板',
                'mission' => '從市場資料與案例中建議新增語句、片語、段落模板與文章模板，所有內容先進待審區。',
                'settings' => ['priority' => 30, 'phase' => 'language'],
            ],
            [
                'slug' => 'rule-governor',
                'name' => '規則檢查員',
                'scope' => '技術、籌碼、財報、題材、五張卡片分類規則',
                'mission' => '檢查系統判斷邏輯是否與實際資料矛盾，提出修正案件給 Codex。',
                'settings' => ['priority' => 40, 'phase' => 'rules'],
            ],
            [
                'slug' => 'ollama-orchestrator',
                'name' => 'Ollama 統管員',
                'scope' => '本機模型統整、報告組裝、代理人建議彙整',
                'mission' => '用 VPS 本機模型統整代理人建議，組裝語句與段落，但不直接修改正式網站。',
                'settings' => ['priority' => 50, 'phase' => 'review', 'model' => 'qwen2.5:1.5b'],
            ],
            [
                'slug' => 'validation-agent',
                'name' => '驗證員',
                'scope' => '五張卡片命中率、分類準確度、後續股價表現',
                'mission' => '每天追蹤卡片候選股後續表現，驗證當初篩選條件是否有效。',
                'settings' => ['priority' => 60, 'phase' => 'validation'],
            ],
        ];

        if ($this->dryRun) {
            return;
        }

        foreach ($roles as $role) {
            DB::table('agent_roles')->updateOrInsert(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'scope' => $role['scope'],
                    'mission' => $role['mission'],
                    'is_active' => true,
                    'settings' => $this->json($role['settings']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * @param callable(): array<string,mixed> $callback
     */
    private function runPhase(string $roleSlug, string $phase, callable $callback): void
    {
        $roleId = DB::table('agent_roles')->where('slug', $roleSlug)->value('id');
        $startedAt = now();
        $runId = null;

        if (! $this->dryRun && $roleId) {
            $runId = DB::table('agent_runs')->insertGetId([
                'agent_role_id' => $roleId,
                'run_key' => "{$roleSlug}:{$phase}:{$this->date}:".now('Asia/Taipei')->format('His'),
                'status' => 'running',
                'started_at' => $startedAt,
                'input_context' => $this->json([
                    'phase' => $phase,
                    'date' => $this->date,
                    'limit' => $this->limit,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $result = $callback();
        $summary = (string) ($result['summary'] ?? "{$phase} completed.");

        if (! $this->dryRun && $runId) {
            DB::table('agent_runs')->where('id', $runId)->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => (int) round($startedAt->diffInMilliseconds(now())),
                'findings_count' => (int) ($result['findings_count'] ?? 0),
                'memories_count' => (int) ($result['memories_count'] ?? 0),
                'summary' => $summary,
                'output_context' => $this->json($result),
                'updated_at' => now(),
            ]);

            DB::table('agent_learning_suggestions')
                ->whereNull('agent_run_id')
                ->where('created_at', '>=', $startedAt)
                ->where('created_at', '<=', now())
                ->where('agent_role_id', $roleId)
                ->update(['agent_run_id' => $runId, 'updated_at' => now()]);
        }

        $this->line($summary);
    }

    /**
     * @return array<string,mixed>
     */
    private function collectKnowledge(): array
    {
        $inserted = 0;
        $updated = 0;

        $events = DB::table('global_events')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($this->limit)
            ->get();

        foreach ($events as $event) {
            [$didInsert, $didUpdate] = $this->upsertKnowledge([
                'knowledge_type' => 'news',
                'source_type' => 'global_events',
                'source_id' => (string) $event->id,
                'source_name' => $event->source,
                'knowledge_date' => $event->event_date ? CarbonImmutable::parse($event->event_date)->toDateString() : $this->date,
                'occurred_at' => $event->event_date,
                'title' => (string) $event->title,
                'summary' => $event->summary,
                'category' => $event->category,
                'region' => $event->region,
                'sentiment' => $event->impact_direction,
                'importance_score' => (int) ($event->impact_score ?? 50),
                'confidence_score' => 70,
                'evidence_payload' => [
                    'raw_payload' => $this->decodeJson($event->raw_payload ?? null),
                ],
                'expires_at' => now()->addDays(21),
            ]);
            $inserted += $didInsert;
            $updated += $didUpdate;
        }

        $clusters = DB::table('global_event_clusters')
            ->orderByDesc('cluster_date')
            ->orderByDesc('importance_score')
            ->limit($this->limit)
            ->get();

        foreach ($clusters as $cluster) {
            [$didInsert, $didUpdate] = $this->upsertKnowledge([
                'knowledge_type' => 'event',
                'source_type' => 'global_event_clusters',
                'source_id' => (string) $cluster->id,
                'source_name' => 'MarketX event cluster',
                'knowledge_date' => $cluster->cluster_date,
                'occurred_at' => CarbonImmutable::parse($cluster->cluster_date, 'Asia/Taipei')->startOfDay(),
                'title' => (string) $cluster->title,
                'summary' => $cluster->summary,
                'category' => $cluster->category,
                'region' => $cluster->region,
                'sentiment' => $cluster->sentiment,
                'importance_score' => (int) $cluster->importance_score,
                'confidence_score' => 75,
                'themes' => $this->decodeJson($cluster->themes),
                'industries' => $this->decodeJson($cluster->industries),
                'symbols' => $this->decodeJson($cluster->related_symbols),
                'evidence_payload' => [
                    'event_ids' => $this->decodeJson($cluster->event_ids),
                    'ai_payload' => $this->decodeJson($cluster->ai_payload),
                ],
                'expires_at' => now()->addDays(30),
            ]);
            $inserted += $didInsert;
            $updated += $didUpdate;
        }

        $reports = collect()
            ->merge(DB::table('global_ai_reports')->orderByDesc('report_date')->limit(10)->get()->map(fn ($row) => ['type' => 'global_report', 'row' => $row]))
            ->merge(DB::table('theme_ai_reports')->orderByDesc('report_date')->limit(10)->get()->map(fn ($row) => ['type' => 'theme_report', 'row' => $row]));

        foreach ($reports as $report) {
            $row = $report['row'];
            [$didInsert, $didUpdate] = $this->upsertKnowledge([
                'knowledge_type' => $report['type'],
                'source_type' => $report['type'].'s',
                'source_id' => (string) $row->id,
                'source_name' => $row->model,
                'knowledge_date' => $row->report_date,
                'occurred_at' => $row->created_at,
                'title' => $row->title ?: ($report['type'] === 'global_report' ? '全球盤前觀察' : '題材盤前觀察'),
                'summary' => Str::limit((string) $row->summary, 900, ''),
                'body' => $row->summary,
                'category' => $report['type'],
                'importance_score' => 80,
                'confidence_score' => 78,
                'evidence_payload' => [
                    'data_pack' => $this->decodeJson($row->data_pack),
                    'model' => $row->model,
                ],
                'expires_at' => now()->addDays(14),
            ]);
            $inserted += $didInsert;
            $updated += $didUpdate;
        }

        return [
            'summary' => "第一階段完成：知識來源新增 {$inserted} 筆、更新 {$updated} 筆。",
            'findings_count' => $inserted + $updated,
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function classifyKnowledge(): array
    {
        $updated = 0;
        $items = DB::table('market_knowledge_items')
            ->whereIn('knowledge_type', ['news', 'event', 'global_report', 'theme_report'])
            ->where(function ($query) {
                $query->whereNull('keywords')
                    ->orWhereNull('themes')
                    ->orWhereNull('industries');
            })
            ->orderByDesc('knowledge_date')
            ->limit($this->limit)
            ->get();

        $themeNames = DB::table('themes')->where('is_active', true)->pluck('name')->map(fn ($name) => (string) $name)->all();

        foreach ($items as $item) {
            $text = implode(' ', array_filter([(string) $item->title, (string) $item->summary, (string) $item->body]));
            $keywords = $this->extractKeywords($text);
            $themes = $this->matchThemes($text, $themeNames);
            $industries = $this->inferIndustries($text, $themes);
            $sentiment = $item->sentiment ?: $this->inferSentiment($text);

            if (! $this->dryRun) {
                DB::table('market_knowledge_items')->where('id', $item->id)->update([
                    'keywords' => $this->json($keywords),
                    'themes' => $this->json($themes),
                    'industries' => $this->json($industries),
                    'sentiment' => $sentiment,
                    'updated_at' => now(),
                ]);
            }
            $updated++;
        }

        $expired = DB::table('market_knowledge_items')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        if (! $this->dryRun && $expired > 0) {
            DB::table('market_knowledge_items')
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }

        return [
            'summary' => "第二階段完成：分類 {$updated} 筆知識，過期 {$expired} 筆。",
            'findings_count' => $updated + $expired,
            'classified' => $updated,
            'expired' => $expired,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function suggestLanguageAssets(): array
    {
        $roleId = DB::table('agent_roles')->where('slug', 'language-curator')->value('id');
        $suggestions = collect($this->baseLanguageSuggestions())
            ->merge($this->knowledgeDrivenLanguageSuggestions())
            ->merge($this->newsDrivenLanguageSuggestions())
            ->merge($this->themeKnowledgeLanguageSuggestions())
            ->merge($this->industryKnowledgeParagraphSuggestions())
            ->merge($this->historicalCaseLanguageSuggestions())
            ->reject(fn (array $suggestion) => $this->learningSuggestionExists($suggestion))
            ->take($this->limit)
            ->values();

        $inserted = 0;
        foreach ($suggestions as $suggestion) {
            if (! $this->dryRun) {
                DB::table('agent_learning_suggestions')->insert([
                    'agent_role_id' => $roleId,
                    'suggestion_type' => $suggestion['suggestion_type'],
                    'target_table' => $suggestion['target_table'],
                    'status' => 'pending',
                    'priority' => (int) round((float) $suggestion['priority']),
                    'title' => $suggestion['title'],
                    'rationale' => $suggestion['rationale'],
                    'proposed_payload' => $this->json($suggestion['proposed_payload']),
                    'evidence_payload' => $this->json($suggestion['evidence_payload'] ?? []),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $inserted++;
        }

        return [
            'summary' => "第三階段完成：新增 {$inserted} 筆語言/模板待審建議。",
            'findings_count' => $inserted,
            'inserted_suggestions' => $inserted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectRules(): array
    {
        $roleId = DB::table('agent_roles')->where('slug', 'rule-governor')->value('id');
        $created = 0;

        $latestCardDate = DB::table('stock_radar_cards')->max('card_date');
        $riskRows = $latestCardDate
            ? DB::table('stock_radar_cards as c')
                ->join('stock_scores as sc', 'sc.stock_id', '=', 'c.stock_id')
                ->where('c.card_date', $latestCardDate)
                ->where('c.card_type', 'risk')
                ->where('sc.confidence_score', '>=', 75)
                ->limit(10)
                ->get(['c.id', 'c.stock_id', 'c.confidence_score as card_confidence', 'sc.confidence_score as stock_confidence', 'c.reasons'])
            : collect();

        if ($riskRows->isNotEmpty()) {
            $created += $this->createRuleSuggestion($roleId, [
                'title' => '風險升高股與看多信心可能矛盾',
                'rationale' => '風險卡片應警示漲多、估值或籌碼風險；若個股頁仍呈現高信心，使用者會感覺方向不一致。',
                'priority' => 85,
                'proposed_payload' => [
                    'rule_area' => 'stock_confidence_and_risk_cards',
                    'recommendation' => '風險升高股仍以看多信心計算，但個股評價文字與狀態要明確描述「風險升高」而不是延續偏多。',
                    'sample_rows' => $riskRows->map(fn ($row) => (array) $row)->values()->all(),
                ],
            ]);
        }

        $missingThemeRows = $latestCardDate
            ? DB::table('stock_radar_cards as c')
                ->join('stock_scores as sc', 'sc.stock_id', '=', 'c.stock_id')
                ->where('c.card_date', $latestCardDate)
                ->whereIn('c.card_type', ['priority', 'potential'])
                ->where(function ($query) {
                    $query->whereNull('sc.theme_score')->orWhere('sc.theme_score', '<=', 0);
                })
                ->limit(10)
                ->get(['c.id', 'c.stock_id', 'c.card_type', 'c.confidence_score', 'sc.theme_score'])
            : collect();

        if ($missingThemeRows->isNotEmpty()) {
            $created += $this->createRuleSuggestion($roleId, [
                'title' => '優先/潛力卡片存在題材分數不足個股',
                'rationale' => '優先觀察與潛力觀察應至少具備題材、技術、籌碼或財報其中多項支撐；題材為 0 時要避免被題材邏輯誤導。',
                'priority' => 75,
                'proposed_payload' => [
                    'rule_area' => 'radar_card_theme_gate',
                    'recommendation' => '若 theme_score <= 0，除非技術與財報條件非常明確，否則不可進入優先/潛力卡片。',
                    'sample_rows' => $missingThemeRows->map(fn ($row) => (array) $row)->values()->all(),
                ],
            ]);
        }

        return [
            'summary' => "第四階段完成：產生 {$created} 筆規則檢查待審案件。",
            'findings_count' => $created,
            'created_rule_suggestions' => $created,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function prepareCodexReview(): array
    {
        $roleId = DB::table('agent_roles')->where('slug', 'ollama-orchestrator')->value('id');
        $pending = DB::table('agent_learning_suggestions')
            ->where('status', 'pending')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($pending->isEmpty()) {
            return [
                'summary' => '第五階段完成：目前沒有待 Codex 審查的學習建議。',
                'findings_count' => 0,
            ];
        }

        $created = 0;
        $grouped = $pending->groupBy('suggestion_type');
        foreach ($grouped as $type => $items) {
            $title = match ($type) {
                'language_asset' => '語言素材待審',
                'paragraph_template' => '段落模板待審',
                'article_template' => '文章模板待審',
                'rule_update' => '規則調整待審',
                default => '學習建議待審',
            };

            $caseTitle = $title.'：'.$items->count().' 筆';
            if ($this->agentFindingExists($caseTitle)) {
                continue;
            }

            if (! $this->dryRun) {
                DB::table('agent_findings')->insert([
                    'agent_role_id' => $roleId,
                    'status' => 'pending',
                    'severity' => $type === 'rule_update' ? 'medium' : 'low',
                    'finding_type' => 'learning_suggestion_review',
                    'page' => 'admin',
                    'title' => $caseTitle,
                    'description' => '代理人已建立可審查的學習建議。Codex 需判斷是否發布到正式語句庫、段落模板、文章模板或規則文件。',
                    'evidence' => $items->pluck('id')->map(fn ($id) => 'ALS-'.$id)->implode(', '),
                    'recommendation' => '逐筆審查 agent_learning_suggestions，合理者發布，不合理者駁回並寫入 reviewer_note。',
                    'payload' => $this->json([
                        'suggestion_type' => $type,
                        'suggestion_ids' => $items->pluck('id')->values()->all(),
                        'preview' => $items->take(5)->map(fn ($item) => [
                            'id' => $item->id,
                            'priority' => $item->priority,
                            'title' => $item->title,
                            'rationale' => $item->rationale,
                        ])->values()->all(),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $created++;
        }

        return [
            'summary' => "第五階段完成：彙整 {$pending->count()} 筆建議，建立 {$created} 筆 Codex 待審案件。",
            'findings_count' => $created,
            'pending_suggestions' => $pending->count(),
            'created_findings' => $created,
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return array{0:int,1:int}
     */
    private function upsertKnowledge(array $values): array
    {
        $lookup = [
            'knowledge_type' => $values['knowledge_type'],
            'source_type' => $values['source_type'] ?? null,
            'source_id' => $values['source_id'] ?? null,
        ];

        $existing = DB::table('market_knowledge_items')->where($lookup)->first();
        $payload = [
            'source_name' => $values['source_name'] ?? null,
            'source_url' => $values['source_url'] ?? null,
            'knowledge_date' => $values['knowledge_date'] ?? null,
            'occurred_at' => $values['occurred_at'] ?? null,
            'title' => $values['title'],
            'summary' => $values['summary'] ?? null,
            'body' => $values['body'] ?? null,
            'category' => $values['category'] ?? null,
            'region' => $values['region'] ?? null,
            'sentiment' => $values['sentiment'] ?? null,
            'importance_score' => $this->clampScore((int) ($values['importance_score'] ?? 50)),
            'confidence_score' => $this->clampScore((int) ($values['confidence_score'] ?? 70)),
            'themes' => $this->nullableJson($values['themes'] ?? null),
            'industries' => $this->nullableJson($values['industries'] ?? null),
            'symbols' => $this->nullableJson($values['symbols'] ?? null),
            'keywords' => $this->nullableJson($values['keywords'] ?? null),
            'evidence_payload' => $this->nullableJson($values['evidence_payload'] ?? null),
            'status' => $values['status'] ?? 'active',
            'expires_at' => $values['expires_at'] ?? null,
            'updated_at' => now(),
        ];

        if ($this->dryRun) {
            return $existing ? [0, 1] : [1, 0];
        }

        if ($existing) {
            DB::table('market_knowledge_items')->where('id', $existing->id)->update($payload);

            return [0, 1];
        }

        DB::table('market_knowledge_items')->insert(array_merge($lookup, $payload, ['created_at' => now()]));

        return [1, 0];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function baseLanguageSuggestions(): array
    {
        return [
            [
                'suggestion_type' => 'language_asset',
                'target_table' => 'language_assets',
                'priority' => 70,
                'title' => '加入股價轉折片語：由弱轉強',
                'rationale' => '用來描述低檔或回檔後的放量上攻，避免所有反彈都寫成過熱。',
                'proposed_payload' => [
                    'asset_type' => 'phrase',
                    'section' => 'price_theme',
                    'tone' => 'bull',
                    'condition_key' => 'rebound_from_base',
                    'text' => '股價先前整理或回落一段時間，今日放量轉強，代表買盤開始重新測試上方壓力。',
                    'weight' => 70,
                    'source' => 'agent',
                ],
            ],
            [
                'suggestion_type' => 'language_asset',
                'target_table' => 'language_assets',
                'priority' => 68,
                'title' => '加入高檔風險片語：漲幅與基本面落差',
                'rationale' => '風險升高股常見問題不是單日下跌，而是價格已提前反映太多期待。',
                'proposed_payload' => [
                    'asset_type' => 'phrase',
                    'section' => 'fundamental',
                    'tone' => 'risk',
                    'condition_key' => 'valuation_expectation_gap',
                    'text' => '目前股價已反映較高期待，若後續營收或毛利率沒有跟上，容易出現評價修正壓力。',
                    'weight' => 72,
                    'source' => 'agent',
                ],
            ],
            [
                'suggestion_type' => 'language_asset',
                'target_table' => 'language_assets',
                'priority' => 62,
                'title' => '加入自然轉折連接詞',
                'rationale' => '報告段落需要更自然地承接技術、籌碼與題材。',
                'proposed_payload' => [
                    'asset_type' => 'connector',
                    'section' => 'summary',
                    'tone' => 'neutral',
                    'condition_key' => 'transition',
                    'text' => '不過，這個判斷仍要回到量能與法人延續性來確認。',
                    'weight' => 55,
                    'source' => 'agent',
                ],
            ],
            [
                'suggestion_type' => 'paragraph_template',
                'target_table' => 'paragraph_templates',
                'priority' => 76,
                'title' => '段落模板：題材帶動但股價已拉高',
                'rationale' => '避免風險股仍被寫成單純偏多，需同時說明題材與追價風險。',
                'proposed_payload' => [
                    'template_key' => 'theme_hot_price_extended_v1',
                    'name' => '題材升溫但股價已拉高',
                    'section' => 'price_theme',
                    'scenario' => 'risk',
                    'tone' => 'risk',
                    'body_template' => '{stock_name}近期受{theme_text}帶動，股價已先反映一段期待。若今日量能放大但收盤沒有延續強勢，代表短線資金可能開始分歧，後續要觀察是否跌破短均線或法人買盤轉弱。',
                    'required_conditions' => ['theme_hot_price_up', 'price_extended'],
                    'optional_conditions' => ['upper_shadow', 'margin_high', 'price_fundamental_gap'],
                    'weight' => 80,
                    'source' => 'agent',
                ],
            ],
            [
                'suggestion_type' => 'article_template',
                'target_table' => 'article_templates',
                'priority' => 82,
                'title' => '文章模板：個股完整健檢',
                'rationale' => '讓個股報告依狀態選擇文章架構，而不是固定硬套五段式。',
                'proposed_payload' => [
                    'template_key' => 'stock_health_check_balanced_v1',
                    'name' => '個股健檢標準模板',
                    'scenario' => 'balanced',
                    'tone' => 'neutral',
                    'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                    'opening_template' => '先看股價位置，再看技術、籌碼與財報是否互相支持。',
                    'closing_template' => '若後續條件沒有延續，應重新檢查原本的觀察理由是否仍成立。',
                    'style_rules' => [
                        'avoid_repeated_phrases' => true,
                        'must_reference_recent_price_action' => true,
                        'must_match_radar_card_type' => true,
                    ],
                    'selection_rules' => [
                        'default' => true,
                    ],
                    'weight' => 70,
                    'source' => 'agent',
                ],
            ],
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function knowledgeDrivenLanguageSuggestions(): Collection
    {
        return DB::table('market_knowledge_items')
            ->where('status', 'active')
            ->whereIn('knowledge_type', ['event', 'theme_report', 'global_report'])
            ->orderByDesc('importance_score')
            ->orderByDesc('knowledge_date')
            ->limit(20)
            ->get()
            ->map(function ($item) {
                $themes = collect($this->decodeJson($item->themes))->filter()->take(2)->implode('、');
                $themes = $themes !== '' ? $themes : '相關題材';

                return [
                    'suggestion_type' => 'language_asset',
                    'target_table' => 'language_assets',
                    'priority' => min(90, 50 + (int) $item->importance_score / 2),
                    'title' => '事件引用句：'.$item->title,
                    'rationale' => '把近期事件轉成可引用的報告語句，但仍需 Codex 審查避免過度推論。',
                    'proposed_payload' => [
                        'asset_type' => 'sentence',
                        'section' => 'price_theme',
                        'tone' => $item->sentiment === 'negative' ? 'risk' : 'neutral',
                        'condition_key' => 'event_reference',
                        'text' => '近期市場焦點包含「'.$item->title.'」，與'.$themes.'有關，後續需觀察是否反映到代表股量價與法人買盤。',
                        'weight' => 60,
                        'source' => 'agent',
                    ],
                    'evidence_payload' => [
                        'knowledge_item_id' => $item->id,
                        'knowledge_date' => $item->knowledge_date,
                        'summary' => $item->summary,
                    ],
                ];
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function newsDrivenLanguageSuggestions(): Collection
    {
        if (! Schema::hasTable('news_items')) {
            return collect();
        }

        return DB::table('news_items')
            ->where('status', 'active')
            ->where('news_date', '>=', CarbonImmutable::now('Asia/Taipei')->subDays(5)->toDateString())
            ->orderByDesc('importance_score')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get()
            ->map(function ($news) {
                $tone = match ($news->sentiment) {
                    'positive' => 'bull',
                    'negative' => 'risk',
                    default => 'neutral',
                };
                $section = $news->category === 'company_disclosure' ? 'fundamental' : 'price_theme';
                $condition = $news->category === 'company_disclosure' ? 'company_disclosure_reference' : 'fresh_news_reference';
                $title = $this->cleanTrainingText((string) $news->title, 70);
                $source = $this->cleanTrainingText((string) $news->source_name, 30);
                $text = match ($section) {
                    'fundamental' => "市場會把「{$title}」視為公司訊息面的新線索，短線不只看標題本身，也要回到營收、獲利與法人反應確認影響是否擴大。",
                    default => "市場今天把焦點放在「{$title}」，相關題材容易被資金重新檢視；若代表股同步放量轉強，題材熱度才比較有延續性。",
                };

                return [
                    'suggestion_type' => 'language_asset',
                    'target_table' => 'language_assets',
                    'priority' => min(88, 52 + (int) $news->importance_score / 2),
                    'title' => '新聞語句候選：'.$title,
                    'rationale' => "由 {$source} 新聞轉成可重複使用的分析語句，避免直接搬用新聞內容。",
                    'proposed_payload' => [
                        'asset_type' => 'sentence',
                        'section' => $section,
                        'tone' => $tone,
                        'condition_key' => $condition,
                        'text' => $text,
                        'weight' => 62,
                        'source' => 'news_agent',
                        'metadata' => [
                            'news_item_id' => $news->id,
                            'source_name' => $news->source_name,
                            'category' => $news->category,
                        ],
                    ],
                    'evidence_payload' => [
                        'news_item_id' => $news->id,
                        'source_name' => $news->source_name,
                        'url' => $news->url,
                        'news_date' => $news->news_date,
                        'sentiment' => $news->sentiment,
                    ],
                ];
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function themeKnowledgeLanguageSuggestions(): Collection
    {
        if (! Schema::hasTable('theme_knowledge')) {
            return collect();
        }

        return DB::table('theme_knowledge')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(function ($theme) {
                $metrics = $this->decodeJson($theme->latest_metrics);
                $heat = (int) ($metrics['heat_score'] ?? 0);
                $tone = $heat >= 75 ? 'bull' : ($heat <= 35 ? 'risk' : 'neutral');
                $condition = $heat >= 75 ? 'theme_heat_high' : ($heat <= 35 ? 'theme_heat_cooling' : 'theme_watch');
                $themeName = $this->cleanTrainingText((string) $theme->theme_name, 40);
                $text = match ($condition) {
                    'theme_heat_high' => "{$themeName}目前屬於市場關注度偏高的題材，後續重點不是只看熱度分數，而是看代表股能不能擴散、量能能不能跟上。",
                    'theme_heat_cooling' => "{$themeName}熱度已經偏弱，若代表股無法重新站回短線結構，資金容易轉向其他更有延續性的族群。",
                    default => "{$themeName}目前比較像觀察型題材，還需要新聞催化、代表股轉強或法人買盤延續，才有機會升級成主流資金方向。",
                };

                return [
                    'suggestion_type' => 'language_asset',
                    'target_table' => 'language_assets',
                    'priority' => 64 + min(20, max(0, $heat - 50) / 2),
                    'title' => '題材語句候選：'.$themeName,
                    'rationale' => '由題材知識庫與最新熱度轉成題材雷達、個股報告都可使用的句型。',
                    'proposed_payload' => [
                        'asset_type' => 'sentence',
                        'section' => 'price_theme',
                        'tone' => $tone,
                        'condition_key' => $condition,
                        'text' => $text,
                        'weight' => 64,
                        'source' => 'theme_knowledge_agent',
                        'metadata' => [
                            'theme_id' => $theme->theme_id,
                            'heat_score' => $heat,
                        ],
                    ],
                    'evidence_payload' => [
                        'theme_name' => $theme->theme_name,
                        'latest_metrics' => $metrics,
                        'asof_date' => $theme->asof_date,
                    ],
                ];
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function industryKnowledgeParagraphSuggestions(): Collection
    {
        if (! Schema::hasTable('industry_knowledge')) {
            return collect();
        }

        return DB::table('industry_knowledge')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get()
            ->map(function ($industry) {
                $name = $this->cleanTrainingText((string) $industry->industry_name, 40);

                return [
                    'suggestion_type' => 'paragraph_template',
                    'target_table' => 'paragraph_templates',
                    'priority' => 70,
                    'title' => '產業段落模板候選：'.$name,
                    'rationale' => '由產業知識庫建立產業鏈段落，讓個股報告能說明族群位置與資金輪動。',
                    'proposed_payload' => [
                        'template_key' => 'industry_chain_context_'.substr(sha1($name), 0, 10),
                        'name' => $name.'產業鏈位置段落',
                        'section' => 'price_theme',
                        'scenario' => 'industry_chain',
                        'tone' => 'neutral',
                        'body_template' => "{stock_name}所屬的{$name}族群，短線要同時看產業消息、代表股強弱與資金是否擴散。若只有單一個股表態，解讀上要保守；若族群多檔同步轉強，題材可信度會明顯提高。",
                        'required_conditions' => ['industry_matched'],
                        'optional_conditions' => ['theme_hot', 'representative_symbols_strong', 'volume_expand'],
                        'weight' => 72,
                        'source' => 'industry_knowledge_agent',
                        'metadata' => [
                            'industry_name' => $industry->industry_name,
                        ],
                    ],
                    'evidence_payload' => [
                        'industry_name' => $industry->industry_name,
                        'representative_symbols' => $this->decodeJson($industry->representative_symbols),
                    ],
                ];
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function historicalCaseLanguageSuggestions(): Collection
    {
        if (! Schema::hasTable('historical_cases')) {
            return collect();
        }

        return DB::table('historical_cases')
            ->where('status', 'active')
            ->orderByDesc('case_date')
            ->limit(20)
            ->get()
            ->map(function ($case) {
                $payload = $this->decodeJson($case->outcome_payload ?? null);
                $avg = isset($payload['avg_change_pct']) ? (float) $payload['avg_change_pct'] : null;
                $tone = $avg === null ? 'neutral' : ($avg > 0 ? 'bull' : 'risk');
                $condition = $avg === null ? 'historical_case_reference' : ($avg > 0 ? 'historical_case_worked' : 'historical_case_failed');
                $caseTitle = $this->cleanTrainingText((string) $case->title, 60);
                $text = $avg === null
                    ? "歷史案例顯示，類似條件出現時不能只看單一訊號，仍要搭配後續量價與籌碼是否延續。"
                    : ($avg > 0
                        ? "歷史案例中，類似條件後續平均表現偏正向，但仍要確認當下是否有同樣的量價與題材支撐。"
                        : "歷史案例中，類似條件後續容易失效，若當下又出現量能退潮或法人轉賣，解讀上要更保守。");

                return [
                    'suggestion_type' => 'language_asset',
                    'target_table' => 'language_assets',
                    'priority' => 66,
                    'title' => '歷史案例語句候選：'.$caseTitle,
                    'rationale' => '把五張卡片追蹤驗證結果轉成可提醒使用者的風險/延續性語句。',
                    'proposed_payload' => [
                        'asset_type' => 'sentence',
                        'section' => 'summary',
                        'tone' => $tone,
                        'condition_key' => $condition,
                        'text' => $text,
                        'weight' => 66,
                        'source' => 'historical_case_agent',
                        'metadata' => [
                            'historical_case_id' => $case->id,
                            'avg_change_pct' => $avg,
                        ],
                    ],
                    'evidence_payload' => [
                        'historical_case_id' => $case->id,
                        'case_date' => $case->case_date,
                        'outcome_payload' => $payload,
                    ],
                ];
            });
    }

    /**
     * @param array<string,mixed> $suggestion
     */
    private function learningSuggestionExists(array $suggestion): bool
    {
        return DB::table('agent_learning_suggestions')
            ->where('suggestion_type', $suggestion['suggestion_type'])
            ->where('title', $suggestion['title'])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function createRuleSuggestion(?int $roleId, array $payload): int
    {
        if ($this->learningSuggestionExists([
            'suggestion_type' => 'rule_update',
            'title' => $payload['title'],
            'proposed_payload' => $payload['proposed_payload'],
        ])) {
            return 0;
        }

        if (! $this->dryRun) {
            DB::table('agent_learning_suggestions')->insert([
                'agent_role_id' => $roleId,
                'suggestion_type' => 'rule_update',
                'target_table' => 'stock_radar_cards',
                'status' => 'pending',
                'priority' => $payload['priority'] ?? 70,
                'title' => $payload['title'],
                'rationale' => $payload['rationale'],
                'proposed_payload' => $this->json($payload['proposed_payload']),
                'evidence_payload' => $this->json($payload['evidence_payload'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return 1;
    }

    private function agentFindingExists(string $title): bool
    {
        return DB::table('agent_findings')
            ->where('finding_type', 'learning_suggestion_review')
            ->where('title', $title)
            ->whereIn('status', ['pending', 'observing'])
            ->exists();
    }

    private function publishSafeDefaults(): void
    {
        $suggestions = DB::table('agent_learning_suggestions')
            ->where('status', 'pending')
            ->whereIn('suggestion_type', ['language_asset', 'paragraph_template', 'article_template'])
            ->where('priority', '>=', 60)
            ->orderByRaw("case suggestion_type when 'article_template' then 1 when 'paragraph_template' then 2 else 3 end")
            ->orderByDesc('priority')
            ->limit(30)
            ->get();

        foreach ($suggestions as $suggestion) {
            $payload = $this->decodeJson($suggestion->proposed_payload);
            $table = (string) $suggestion->target_table;

            if (! in_array($table, ['language_assets', 'paragraph_templates', 'article_templates'], true)) {
                continue;
            }

            $id = $this->publishPayload($table, $payload);
            DB::table('agent_learning_suggestions')->where('id', $suggestion->id)->update([
                'status' => 'approved',
                'target_id' => $id,
                'reviewed_at' => now(),
                'reviewer_note' => 'Safe default published by pipeline option.',
                'updated_at' => now(),
            ]);
            DB::table('agent_learning_publications')->insert([
                'agent_learning_suggestion_id' => $suggestion->id,
                'published_table' => $table,
                'published_id' => $id,
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publishPayload(string $table, array $payload): int
    {
        $now = now();

        if ($table === 'language_assets') {
            $lookup = [
                'asset_type' => $payload['asset_type'],
                'section' => $payload['section'] ?? null,
                'condition_key' => $payload['condition_key'] ?? null,
                'text' => $payload['text'],
            ];
            $existing = DB::table($table)->where($lookup)->first();
            if ($existing) {
                return (int) $existing->id;
            }

            return (int) DB::table($table)->insertGetId(array_merge($lookup, [
                'tone' => $payload['tone'] ?? 'neutral',
                'weight' => $payload['weight'] ?? 50,
                'source' => $payload['source'] ?? 'agent',
                'status' => 'active',
                'metadata' => $this->nullableJson($payload['metadata'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $key = (string) $payload['template_key'];
        $existing = DB::table($table)->where('template_key', $key)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $payload['status'] ??= 'active';
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        foreach (['required_conditions', 'optional_conditions', 'section_order', 'style_rules', 'selection_rules', 'metadata'] as $jsonColumn) {
            if (array_key_exists($jsonColumn, $payload)) {
                $payload[$jsonColumn] = $this->nullableJson($payload[$jsonColumn]);
            }
        }

        return (int) DB::table($table)->insertGetId($payload);
    }

    /**
     * @return array<int,string>
     */
    private function extractKeywords(string $text): array
    {
        $dictionary = [
            'AI', 'AI Server', 'CoWoS', '散熱', '重電', '電力', '記憶體', 'DRAM', 'NAND',
            '銅', '鋼鐵', '航運', '油價', '黃金', '美元', '美債', 'Fed', 'NVIDIA',
            '台積電', 'Apple', 'Microsoft', '地緣政治', '關稅', '匯率',
        ];

        return collect($dictionary)
            ->filter(fn (string $word) => Str::contains(Str::lower($text), Str::lower($word)))
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $themeNames
     * @return array<int,string>
     */
    private function matchThemes(string $text, array $themeNames): array
    {
        return collect($themeNames)
            ->filter(fn (string $theme) => Str::contains(Str::lower($text), Str::lower($theme)))
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $themes
     * @return array<int,string>
     */
    private function inferIndustries(string $text, array $themes): array
    {
        $industries = [];
        $map = [
            'AI' => 'AI 供應鏈',
            'Server' => '雲端與資料中心',
            '記憶體' => '半導體',
            'DRAM' => '半導體',
            '散熱' => '電子零組件',
            '重電' => '電機機械',
            '銅' => '原物料',
            '鋼鐵' => '原物料',
            '航運' => '航運',
            '油' => '能源',
        ];

        foreach ($map as $needle => $industry) {
            if (Str::contains(Str::lower($text.' '.implode(' ', $themes)), Str::lower($needle))) {
                $industries[] = $industry;
            }
        }

        return array_values(array_unique($industries));
    }

    private function inferSentiment(string $text): string
    {
        $lower = Str::lower($text);
        $riskWords = ['下跌', '衰退', '風險', '制裁', '戰爭', '通膨', '升息', '禁令', '賣壓'];
        $bullWords = ['上漲', '成長', '需求增加', '降息', '買盤', '報價上漲', '突破', '優於預期'];

        if (collect($riskWords)->contains(fn ($word) => Str::contains($lower, Str::lower($word)))) {
            return 'negative';
        }

        if (collect($bullWords)->contains(fn ($word) => Str::contains($lower, Str::lower($word)))) {
            return 'positive';
        }

        return 'neutral';
    }

    private function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    private function cleanTrainingText(string $text, int $limit = 80): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return Str::limit($text, $limit, '');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullableJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->json($value);
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
