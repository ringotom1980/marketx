<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateLanguageSuggestionsFromQualityFindings extends Command
{
    protected $signature = 'market:agents-quality-to-language-suggestions
        {--limit=80 : Maximum pending quality findings to inspect}
        {--dry-run : Print suggestions without writing them}';

    protected $description = 'Convert stock report quality findings into language/template learning suggestions.';

    public function handle(): int
    {
        if (! $this->hasRequiredTables()) {
            $this->error('Missing agent learning or finding tables.');

            return self::FAILURE;
        }

        $findings = $this->pendingQualityFindings();
        if ($findings->isEmpty()) {
            $this->info('No pending stock report quality findings to convert.');

            return self::SUCCESS;
        }

        $roleId = DB::table('agent_roles')->where('slug', 'language-curator')->value('id');
        $inserted = 0;

        foreach ($findings->groupBy('finding_type') as $findingType => $items) {
            $suggestion = $this->suggestionFor((string) $findingType, $items);
            if ($suggestion === null || $this->suggestionExists($suggestion['title'])) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line($suggestion['title'].' -> '.$suggestion['target_table']);
                $inserted++;
                continue;
            }

            DB::table('agent_learning_suggestions')->insert([
                'agent_role_id' => $roleId,
                'suggestion_type' => $suggestion['suggestion_type'],
                'target_table' => $suggestion['target_table'],
                'status' => 'pending',
                'priority' => $suggestion['priority'],
                'title' => $suggestion['title'],
                'rationale' => $suggestion['rationale'],
                'proposed_payload' => $this->json($suggestion['proposed_payload']),
                'evidence_payload' => $this->json($suggestion['evidence_payload']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        $this->info("Quality learning suggestions created: {$inserted}");

        return self::SUCCESS;
    }

    private function hasRequiredTables(): bool
    {
        foreach (['agent_findings', 'agent_learning_suggestions', 'agent_roles'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, object>
     */
    private function pendingQualityFindings(): Collection
    {
        return DB::table('agent_findings')
            ->where('status', 'pending')
            ->where('page', 'stock')
            ->whereIn('finding_type', [
                'stock_report_repeated_sentence',
                'stock_report_contradiction',
                'stock_report_card_tone_mismatch',
                'stock_report_too_generic',
            ])
            ->orderByRaw("case severity when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderByDesc('id')
            ->limit(max(1, min(300, (int) $this->option('limit'))))
            ->get(['id', 'finding_type', 'severity', 'symbol', 'title', 'description', 'payload', 'created_at']);
    }

    /**
     * @param Collection<int, object> $items
     * @return array<string,mixed>|null
     */
    private function suggestionFor(string $findingType, Collection $items): ?array
    {
        $date = now('Asia/Taipei')->toDateString();
        $ids = $items->pluck('id')->values()->all();
        $symbols = $items->pluck('symbol')->filter()->unique()->take(12)->values()->all();

        return match ($findingType) {
            'stock_report_repeated_sentence' => [
                'suggestion_type' => 'paragraph_template',
                'target_table' => 'paragraph_templates',
                'priority' => 76,
                'title' => "報告品質回饋：減少重複語句 {$date}",
                'rationale' => '品質稽核發現多篇報告有相似句型重複，應補一個可重用的總評段落模板，讓報告先交代判讀順序，再補個股細節。',
                'proposed_payload' => [
                    'template_key' => 'agent_quality_repeat_guard_'.str_replace('-', '', $date),
                    'name' => '品質回饋：避免重複句型',
                    'section' => 'summary',
                    'scenario' => 'balanced',
                    'tone' => 'neutral',
                    'body_template' => '{stock_name} 的判讀要先分清楚主因與輔助條件：股價位置、量能、籌碼與營收若能互相支持，結論才比較穩；若只有單一訊號成立，報告應保留觀察語氣，避免重複放大同一個理由。',
                    'optional_conditions' => ['follow_up', 'balanced_bull', 'balanced_risk'],
                    'weight' => 67,
                    'source' => 'agent_quality',
                    'metadata' => ['quality_finding_type' => $findingType],
                ],
                'evidence_payload' => $this->evidence($findingType, $ids, $symbols),
            ],
            'stock_report_contradiction', 'stock_report_card_tone_mismatch' => [
                'suggestion_type' => 'paragraph_template',
                'target_table' => 'paragraph_templates',
                'priority' => 84,
                'title' => "報告品質回饋：對齊卡片方向 {$date}",
                'rationale' => '品質稽核發現個股報告與五張卡片分類方向可能不一致，應補一個總評模板，要求先對齊卡片分類，再說明延續條件或失效條件。',
                'proposed_payload' => [
                    'template_key' => 'agent_quality_card_alignment_'.str_replace('-', '', $date),
                    'name' => '品質回饋：卡片方向一致性',
                    'section' => 'summary',
                    'scenario' => 'card_alignment',
                    'tone' => 'neutral',
                    'body_template' => '{stock_name} 的總評要先對齊目前分類：若屬優先或潛力觀察，重點放在趨勢延續與資金是否跟上；若屬風險升高或持續弱勢，重點應放在壓力來源、失效條件與需要等待的確認訊號。',
                    'optional_conditions' => ['balanced_bull', 'balanced_risk', 'contrast'],
                    'weight' => 82,
                    'source' => 'agent_quality',
                    'metadata' => ['quality_finding_type' => $findingType],
                ],
                'evidence_payload' => $this->evidence($findingType, $ids, $symbols),
            ],
            'stock_report_too_generic' => [
                'suggestion_type' => 'language_asset',
                'target_table' => 'language_assets',
                'priority' => 72,
                'title' => "報告品質回饋：增加近期走勢細節 {$date}",
                'rationale' => '品質稽核發現部分報告數字與近期走勢描述不足，應補一條價格題材段落可用語句，要求帶入近 5 日、近 20 日與量能判斷。',
                'proposed_payload' => [
                    'asset_type' => 'sentence',
                    'section' => 'price_theme',
                    'tone' => 'neutral',
                    'condition_key' => 'price_sideways',
                    'text' => '{stock_name} 近期要同時看近 5 日、近 20 日漲跌幅與量能變化；若價格有波動但量能沒有同步放大，題材解讀就要保留彈性。',
                    'weight' => 70,
                    'source' => 'agent_quality',
                    'metadata' => ['quality_finding_type' => $findingType],
                ],
                'evidence_payload' => $this->evidence($findingType, $ids, $symbols),
            ],
            default => null,
        };
    }

    /**
     * @param array<int, int> $ids
     * @param array<int, string> $symbols
     * @return array<string,mixed>
     */
    private function evidence(string $findingType, array $ids, array $symbols): array
    {
        return [
            'source' => 'agent_findings',
            'finding_type' => $findingType,
            'finding_ids' => $ids,
            'symbols' => $symbols,
            'generated_at' => now('Asia/Taipei')->toDateTimeString(),
        ];
    }

    private function suggestionExists(string $title): bool
    {
        return DB::table('agent_learning_suggestions')
            ->where('title', $title)
            ->exists();
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
