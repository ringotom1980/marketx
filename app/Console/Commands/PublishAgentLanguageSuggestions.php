<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublishAgentLanguageSuggestions extends Command
{
    protected $signature = 'market:agents-publish-language-suggestions
        {--limit=30 : Maximum pending suggestions to review}
        {--min-priority=60 : Minimum priority to auto-publish}
        {--dry-run : Preview decisions without writing}';

    protected $description = 'Review and publish safe agent language/template suggestions into official language libraries.';

    private bool $dryRun = false;

    public function handle(): int
    {
        if (! $this->requiredTablesExist()) {
            $this->error('Agent learning language tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(200, (int) $this->option('limit')));
        $minPriority = max(0, min(100, (int) $this->option('min-priority')));

        $suggestions = DB::table('agent_learning_suggestions')
            ->where('status', 'pending')
            ->whereIn('suggestion_type', ['language_asset', 'paragraph_template', 'article_template'])
            ->whereIn('target_table', ['language_assets', 'paragraph_templates', 'article_templates'])
            ->where('priority', '>=', $minPriority)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $approved = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($suggestions as $suggestion) {
            $payload = $this->decodeJson($suggestion->proposed_payload);
            $table = (string) $suggestion->target_table;
            $review = $this->reviewPayload($table, $payload);

            if (! $review['approved']) {
                $rejected++;
                $this->line("Reject ALS-{$suggestion->id}: ".$review['note']);

                if (! $this->dryRun) {
                    DB::table('agent_learning_suggestions')->where('id', $suggestion->id)->update([
                        'status' => 'rejected',
                        'reviewed_at' => now(),
                        'reviewer_note' => $review['note'],
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            if ($this->alreadyPublished($table, $payload)) {
                $skipped++;
                $this->line("Skip ALS-{$suggestion->id}: duplicated official asset.");

                if (! $this->dryRun) {
                    DB::table('agent_learning_suggestions')->where('id', $suggestion->id)->update([
                        'status' => 'rejected',
                        'reviewed_at' => now(),
                        'reviewer_note' => 'Duplicate of existing official language/template asset.',
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            $publishedId = $this->dryRun ? 0 : $this->publishPayload($table, $payload);
            $approved++;
            $this->line("Approve ALS-{$suggestion->id}: {$table}#{$publishedId}");

            if (! $this->dryRun) {
                DB::table('agent_learning_suggestions')->where('id', $suggestion->id)->update([
                    'status' => 'approved',
                    'target_id' => $publishedId,
                    'reviewed_at' => now(),
                    'reviewer_note' => $review['note'],
                    'updated_at' => now(),
                ]);

                DB::table('agent_learning_publications')->insert([
                    'agent_learning_suggestion_id' => $suggestion->id,
                    'published_table' => $table,
                    'published_id' => $publishedId,
                    'status' => 'published',
                    'published_at' => now(),
                    'notes' => $review['note'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info("Language suggestions reviewed. Approved: {$approved}; Rejected: {$rejected}; Skipped: {$skipped}.");

        return self::SUCCESS;
    }

    private function requiredTablesExist(): bool
    {
        foreach (['agent_learning_suggestions', 'agent_learning_publications', 'language_assets', 'paragraph_templates', 'article_templates'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{approved:bool,note:string}
     */
    private function reviewPayload(string $table, array $payload): array
    {
        $text = $this->payloadText($table, $payload);
        $plain = $this->plainText($text);

        if (! in_array($table, ['language_assets', 'paragraph_templates', 'article_templates'], true)) {
            return ['approved' => false, 'note' => 'Unsupported target table.'];
        }

        if (mb_strlen($plain) < 24) {
            return ['approved' => false, 'note' => 'Text is too short for official language library.'];
        }

        if (mb_strlen($plain) > 520) {
            return ['approved' => false, 'note' => 'Text is too long and should be converted into a template instead.'];
        }

        if ($this->containsForbiddenAdvice($plain)) {
            return ['approved' => false, 'note' => 'Contains direct buy/sell advice wording.'];
        }

        if ($this->looksLikeRawNewsCopy($plain)) {
            return ['approved' => false, 'note' => 'Looks too close to raw news/clickbait wording.'];
        }

        if ($table === 'language_assets' && empty($payload['asset_type'])) {
            return ['approved' => false, 'note' => 'Missing asset_type.'];
        }

        if (in_array($table, ['paragraph_templates', 'article_templates'], true) && empty($payload['template_key'])) {
            return ['approved' => false, 'note' => 'Missing template_key.'];
        }

        if (in_array($table, ['paragraph_templates', 'article_templates'], true) && ! Str::contains($text, ['{stock_name}', '{theme_text}', '{'])) {
            return ['approved' => false, 'note' => 'Template does not include reusable placeholders.'];
        }

        return ['approved' => true, 'note' => 'Codex rule review passed: reusable, non-directional, and safe for official language library.'];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function payloadText(string $table, array $payload): string
    {
        return match ($table) {
            'language_assets' => (string) ($payload['text'] ?? ''),
            'paragraph_templates' => (string) ($payload['body_template'] ?? ''),
            'article_templates' => trim(((string) ($payload['opening_template'] ?? '')).' '.((string) ($payload['closing_template'] ?? ''))),
            default => '',
        };
    }

    private function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function containsForbiddenAdvice(string $text): bool
    {
        $forbidden = ['強力買進', '買進', '賣出', '減碼', '續抱', '進場', '出場', '抄底', '追價', '保證', '一定會漲', '一定會跌'];

        return collect($forbidden)->contains(fn (string $word) => Str::contains($text, $word));
    }

    private function looksLikeRawNewsCopy(string $text): bool
    {
        $clickbaitMarks = ['快訊', '獨家', '震撼', '驚爆', '懶人包', '一次看', '全網瘋傳'];

        if (collect($clickbaitMarks)->contains(fn (string $word) => Str::contains($text, $word))) {
            return true;
        }

        $punctuationCount = substr_count($text, '！') + substr_count($text, '!') + substr_count($text, '?') + substr_count($text, '？');

        return $punctuationCount >= 3;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function alreadyPublished(string $table, array $payload): bool
    {
        if ($table === 'language_assets') {
            return DB::table($table)
                ->where('text', (string) ($payload['text'] ?? ''))
                ->exists();
        }

        return DB::table($table)
            ->where('template_key', (string) ($payload['template_key'] ?? ''))
            ->exists();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publishPayload(string $table, array $payload): int
    {
        $now = now();

        if ($table === 'language_assets') {
            return (int) DB::table($table)->insertGetId([
                'asset_type' => $payload['asset_type'],
                'section' => $payload['section'] ?? null,
                'tone' => $payload['tone'] ?? 'neutral',
                'condition_key' => $payload['condition_key'] ?? null,
                'text' => $payload['text'],
                'weight' => (int) ($payload['weight'] ?? 50),
                'source' => $payload['source'] ?? 'agent_reviewed',
                'status' => 'active',
                'metadata' => $this->nullableJson($payload['metadata'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $payload['status'] ??= 'active';
        $payload['source'] ??= 'agent_reviewed';
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        foreach (['required_conditions', 'optional_conditions', 'section_order', 'style_rules', 'selection_rules', 'metadata'] as $column) {
            if (array_key_exists($column, $payload)) {
                $payload[$column] = $this->nullableJson($payload[$column]);
            }
        }

        return (int) DB::table($table)->insertGetId($payload);
    }

    private function nullableJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
