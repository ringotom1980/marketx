<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedReportTemplateVariants extends Command
{
    protected $signature = 'market:seed-report-template-variants {--audit : Only show coverage without inserting templates}';

    protected $description = 'Seed additional article and paragraph template variants for stock reports and audit scenario coverage.';

    public function handle(): int
    {
        if (! $this->hasTables()) {
            $this->error('Missing paragraph_templates or article_templates table.');

            return self::FAILURE;
        }

        if (! $this->option('audit')) {
            $paragraphs = $this->seedParagraphTemplates();
            $articles = $this->seedArticleTemplates();

            $this->info("Template variants seeded: paragraphs={$paragraphs}, articles={$articles}");
        }

        $this->auditCoverage();

        return self::SUCCESS;
    }

    private function hasTables(): bool
    {
        return DB::getSchemaBuilder()->hasTable('paragraph_templates')
            && DB::getSchemaBuilder()->hasTable('article_templates');
    }

    private function seedParagraphTemplates(): int
    {
        $count = 0;

        foreach ($this->paragraphTemplates() as $template) {
            DB::table('paragraph_templates')->updateOrInsert(
                ['template_key' => $template['template_key']],
                [
                    'name' => $template['name'],
                    'section' => $template['section'],
                    'scenario' => $template['scenario'],
                    'tone' => $template['tone'],
                    'body_template' => $template['body_template'],
                    'required_conditions' => $this->json($template['required_conditions'] ?? []),
                    'optional_conditions' => $this->json($template['optional_conditions'] ?? []),
                    'weight' => $template['weight'],
                    'source' => 'codex_seed_v3',
                    'status' => 'active',
                    'metadata' => $this->json(['purpose' => 'stock_report_template_diversity']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $count++;
        }

        return $count;
    }

    private function seedArticleTemplates(): int
    {
        $count = 0;

        foreach ($this->articleTemplates() as $template) {
            DB::table('article_templates')->updateOrInsert(
                ['template_key' => $template['template_key']],
                [
                    'name' => $template['name'],
                    'scenario' => $template['scenario'],
                    'tone' => $template['tone'],
                    'section_order' => $this->json($template['section_order']),
                    'opening_template' => $template['opening_template'],
                    'closing_template' => $template['closing_template'],
                    'style_rules' => $this->json($template['style_rules'] ?? []),
                    'selection_rules' => $this->json($template['selection_rules'] ?? []),
                    'weight' => $template['weight'],
                    'source' => 'codex_seed_v3',
                    'status' => 'active',
                    'metadata' => $this->json(['purpose' => 'stock_report_article_diversity']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $count++;
        }

        return $count;
    }

    private function auditCoverage(): void
    {
        $requiredParagraphs = [
            'price_theme' => ['base_rebound', 'trend', 'risk', 'weak', 'balanced'],
            'technical' => ['trend', 'failed_breakout', 'balanced'],
            'chip' => ['institutional', 'margin_risk', 'balanced'],
            'fundamental' => ['growth', 'risk', 'balanced'],
            'summary' => ['card_alignment', 'balanced'],
        ];

        $this->line('Paragraph coverage:');
        foreach ($requiredParagraphs as $section => $scenarios) {
            foreach ($scenarios as $scenario) {
                $count = DB::table('paragraph_templates')
                    ->where('status', 'active')
                    ->where('section', $section)
                    ->where('scenario', $scenario)
                    ->count();

                $this->line(sprintf(' - %s/%s: %d', $section, $scenario, $count));
            }
        }

        $this->line('Article coverage:');
        foreach (['priority', 'risk', 'low_volume_breakout', 'weak_trend', 'potential', 'balanced'] as $scenario) {
            $count = DB::table('article_templates')
                ->where('status', 'active')
                ->where('scenario', $scenario)
                ->count();

            $this->line(sprintf(' - %s: %d', $scenario, $count));
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function paragraphTemplates(): array
    {
        return [
            [
                'template_key' => 'price_theme_trend_continuation_v3a',
                'name' => '股價沿均線墊高',
                'section' => 'price_theme',
                'scenario' => 'trend',
                'tone' => 'bull',
                'body_template' => '{stock_name}近期股價重心持續墊高，搭配{theme_text}仍有市場討論度，代表資金尚未明顯退場。這種型態要看拉回時是否守住短均線，以及量能是否維持溫和。',
                'required_conditions' => ['recent_momentum'],
                'optional_conditions' => ['theme_hot_price_up', 'price_up_volume_up'],
                'weight' => 88,
            ],
            [
                'template_key' => 'price_theme_base_rebound_v3a',
                'name' => '整理後放量轉強',
                'section' => 'price_theme',
                'scenario' => 'base_rebound',
                'tone' => 'bull',
                'body_template' => '{stock_name}前段時間整理後重新放量轉強，表示買盤開始回到盤面。若{theme_text}同步升溫，這比較像資金重新評估題材，而不是單純短線反彈。',
                'required_conditions' => ['base_rebound'],
                'optional_conditions' => ['low_base_breakout'],
                'weight' => 86,
            ],
            [
                'template_key' => 'price_theme_risk_extended_v3a',
                'name' => '漲幅領先題材證據',
                'section' => 'price_theme',
                'scenario' => 'risk',
                'tone' => 'risk',
                'body_template' => '{stock_name}雖然仍有{theme_text}支撐，但股價已經先反映一段期待。若接下來沒有營收、報價、訂單或法人買盤延續，短線容易從追題材轉成檢查風險。',
                'required_conditions' => ['price_extended'],
                'optional_conditions' => ['valuation_gap', 'upper_shadow'],
                'weight' => 90,
            ],
            [
                'template_key' => 'price_theme_weak_downtrend_v3a',
                'name' => '反彈未脫弱勢',
                'section' => 'price_theme',
                'scenario' => 'weak',
                'tone' => 'bear',
                'body_template' => '{stock_name}近期股價仍在弱勢結構內，即使單日反彈，也要先確認是不是只是在跌深後修正。若量能沒有放大、題材也沒有擴散，解讀上要偏保守。',
                'required_conditions' => ['recent_downtrend'],
                'optional_conditions' => ['weak_rebound'],
                'weight' => 84,
            ],
            [
                'template_key' => 'price_theme_balanced_v3a',
                'name' => '價格訊號等待確認',
                'section' => 'price_theme',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'body_template' => '{stock_name}目前價格訊號還沒有明顯單邊方向，重點不是急著下結論，而是觀察是否能突破整理區、量能是否放大，以及{theme_text}是否能繼續吸引資金。',
                'required_conditions' => [],
                'optional_conditions' => ['price_sideways'],
                'weight' => 72,
            ],
            [
                'template_key' => 'technical_trend_v3a',
                'name' => '多方技術結構',
                'section' => 'technical',
                'scenario' => 'trend',
                'tone' => 'bull',
                'body_template' => '技術面若同時看到均線往上、MACD 動能改善、RSI 維持強勢區，代表短線結構偏向多方。不過仍要看量能是否健康，避免只有價格上漲、動能卻沒有跟上。',
                'required_conditions' => ['ma_bull'],
                'optional_conditions' => ['macd_bull', 'rsi_strong'],
                'weight' => 86,
            ],
            [
                'template_key' => 'technical_failed_breakout_v3a',
                'name' => '突破失敗與上影線',
                'section' => 'technical',
                'scenario' => 'failed_breakout',
                'tone' => 'risk',
                'body_template' => '如果股價盤中拉高後收不住，或突破後很快跌回壓力區，代表上方賣壓仍在。這種情況要觀察隔日是否能重新站回關鍵價位。',
                'required_conditions' => ['upper_shadow'],
                'optional_conditions' => ['macd_shrinking', 'bais_high'],
                'weight' => 88,
            ],
            [
                'template_key' => 'technical_balanced_v3a',
                'name' => '技術訊號混合',
                'section' => 'technical',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'body_template' => '技術指標目前屬於混合訊號，單看一個指標容易誤判。比較好的做法是把均線、量能、MACD、KD 與 RSI 放在一起看，確認方向是否一致。',
                'required_conditions' => ['technical_mixed'],
                'optional_conditions' => [],
                'weight' => 72,
            ],
            [
                'template_key' => 'chip_institutional_v3a',
                'name' => '法人延續性觀察',
                'section' => 'chip',
                'scenario' => 'institutional',
                'tone' => 'bull',
                'body_template' => '籌碼面如果外資、投信或主力買盤連續出現，代表資金不是只做一日行情。若買盤能從代表股擴散到同題材其他個股，題材可信度會更高。',
                'required_conditions' => ['institutional_buy'],
                'optional_conditions' => ['foreign_trust_buy'],
                'weight' => 84,
            ],
            [
                'template_key' => 'chip_margin_risk_v3a',
                'name' => '融資與浮動籌碼壓力',
                'section' => 'chip',
                'scenario' => 'margin_risk',
                'tone' => 'risk',
                'body_template' => '籌碼風險主要看融資是否快速增加，以及法人是否開始轉賣。若股價已經漲高但籌碼越來越浮動，拉回時通常會比基本面變化更快反映。',
                'required_conditions' => ['margin_pressure'],
                'optional_conditions' => ['institutional_sell'],
                'weight' => 86,
            ],
            [
                'template_key' => 'chip_balanced_v3a',
                'name' => '籌碼方向等待確認',
                'section' => 'chip',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'body_template' => '目前籌碼方向需要再觀察，單日買賣超參考價值有限。比較重要的是法人是否連續同向，以及融資融券是否讓籌碼變得太浮動。',
                'required_conditions' => ['chip_neutral'],
                'optional_conditions' => [],
                'weight' => 72,
            ],
            [
                'template_key' => 'fundamental_growth_v3a',
                'name' => '營收支撐股價',
                'section' => 'fundamental',
                'scenario' => 'growth',
                'tone' => 'bull',
                'body_template' => '基本面要看營收成長是否能跟上股價。如果月營收、年增率與毛利率方向一致，股價上漲就比較有基本面依據。',
                'required_conditions' => ['revenue_growth'],
                'optional_conditions' => ['profit_quality_good'],
                'weight' => 84,
            ],
            [
                'template_key' => 'fundamental_risk_v3a',
                'name' => '股價與基本面落差',
                'section' => 'fundamental',
                'scenario' => 'risk',
                'tone' => 'risk',
                'body_template' => '若股價漲幅明顯領先營收或獲利，後續就需要更強的財報證據支撐。否則市場很容易從想像空間回到估值檢查。',
                'required_conditions' => ['valuation_gap'],
                'optional_conditions' => ['per_high', 'pb_high'],
                'weight' => 86,
            ],
            [
                'template_key' => 'fundamental_balanced_v3a',
                'name' => '財務資料等待確認',
                'section' => 'fundamental',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'body_template' => '財務面目前比較適合當成輔助判斷。若營收、EPS、毛利率或 ROE 沒有同步轉強，就不宜只靠題材或技術訊號解讀。',
                'required_conditions' => ['fundamental_stable'],
                'optional_conditions' => [],
                'weight' => 72,
            ],
            [
                'template_key' => 'summary_card_alignment_v3a',
                'name' => '分類與條件一致性',
                'section' => 'summary',
                'scenario' => 'card_alignment',
                'tone' => 'neutral',
                'body_template' => '總結要回到目前分類是否合理：若是優先觀察，重點是條件能否延續；若是風險升高，重點不是看空，而是提醒漲幅、籌碼或估值可能需要重新檢查。',
                'required_conditions' => ['card_type'],
                'optional_conditions' => ['priority', 'risk', 'potential'],
                'weight' => 84,
            ],
            [
                'template_key' => 'summary_balanced_v3a',
                'name' => '總評等待關鍵條件',
                'section' => 'summary',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'body_template' => '整體來看，這檔股票還需要幾個條件互相驗證。最重要的是股價、量能、法人與基本面是否同向，而不是只因為單一訊號就提高判斷。',
                'required_conditions' => ['overall_watch'],
                'optional_conditions' => [],
                'weight' => 72,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function articleTemplates(): array
    {
        return [
            [
                'template_key' => 'stock_priority_observation_v3a',
                'name' => '優先觀察股結構版',
                'scenario' => 'priority',
                'tone' => 'bull',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '{stock_name}目前的重點是上漲條件是否能延續，而不是只看單日漲跌。',
                'closing_template' => '後續若題材、量能與法人買盤能延續，觀察理由才算成立；反之就要把條件重新檢查。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_reference_recent_price_action' => true],
                'selection_rules' => ['scenario' => 'priority'],
                'weight' => 90,
            ],
            [
                'template_key' => 'stock_priority_observation_v3b',
                'name' => '優先觀察股風險平衡版',
                'scenario' => 'priority',
                'tone' => 'bull',
                'section_order' => ['price_theme', 'chip', 'technical', 'fundamental', 'summary'],
                'opening_template' => '{stock_name}目前有條件值得追蹤，但仍要確認資金與基本面是否同步。',
                'closing_template' => '如果只有價格表態、籌碼或營收沒有跟上，就要降低解讀強度。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_balance_risk' => true],
                'selection_rules' => ['scenario' => 'priority'],
                'weight' => 84,
            ],
            [
                'template_key' => 'stock_risk_observation_v3a',
                'name' => '風險升高估值籌碼版',
                'scenario' => 'risk',
                'tone' => 'risk',
                'section_order' => ['price_theme', 'fundamental', 'chip', 'technical', 'summary'],
                'opening_template' => '{stock_name}被放在風險觀察時，核心不是直接看空，而是檢查股價是否已經領先基本面或籌碼條件。',
                'closing_template' => '後續若營收、法人或題材沒有補上證據，短線波動就可能放大。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_explain_risk_source' => true],
                'selection_rules' => ['scenario' => 'risk'],
                'weight' => 92,
            ],
            [
                'template_key' => 'stock_risk_observation_v3b',
                'name' => '風險升高技術轉弱版',
                'scenario' => 'risk',
                'tone' => 'risk',
                'section_order' => ['technical', 'price_theme', 'chip', 'fundamental', 'summary'],
                'opening_template' => '{stock_name}目前要先看技術面是否出現轉弱訊號，再回頭檢查籌碼與基本面是否支撐得住。',
                'closing_template' => '若拉回時量能失控或法人轉賣，原本的觀察條件就需要降級。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_reference_technical_risk' => true],
                'selection_rules' => ['scenario' => 'risk'],
                'weight' => 86,
            ],
            [
                'template_key' => 'stock_low_volume_breakout_v3a',
                'name' => '低檔爆量初動版',
                'scenario' => 'low_volume_breakout',
                'tone' => 'neutral',
                'section_order' => ['price_theme', 'technical', 'chip', 'summary'],
                'opening_template' => '{stock_name}若屬於低檔爆量，重點是確認這是資金回流，還是短線反彈。',
                'closing_template' => '後續要看量能是否維持、股價是否守住爆量 K 棒低點，以及同族群是否跟進。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_reference_volume' => true],
                'selection_rules' => ['scenario' => 'low_volume_breakout'],
                'weight' => 88,
            ],
            [
                'template_key' => 'stock_weak_trend_v3a',
                'name' => '持續弱勢趨勢版',
                'scenario' => 'weak_trend',
                'tone' => 'bear',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '{stock_name}目前若仍在弱勢分類，重點是確認下跌結構是否已經被扭轉。',
                'closing_template' => '沒有重新站回關鍵均線或出現連續資金回流前，解讀上要保持保守。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_avoid_overheat_words_when_downtrend' => true],
                'selection_rules' => ['scenario' => 'weak_trend'],
                'weight' => 88,
            ],
            [
                'template_key' => 'stock_potential_watch_v3a',
                'name' => '潛力觀察條件確認版',
                'scenario' => 'potential',
                'tone' => 'neutral',
                'section_order' => ['price_theme', 'technical', 'fundamental', 'chip', 'summary'],
                'opening_template' => '{stock_name}目前比較像條件逐步成形，還不到完全確認的階段。',
                'closing_template' => '若後續題材熱度、量能與財務資料能一起改善，觀察價值才會提高。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'must_explain_unconfirmed_conditions' => true],
                'selection_rules' => ['scenario' => 'potential'],
                'weight' => 86,
            ],
            [
                'template_key' => 'stock_balanced_health_check_v3a',
                'name' => '一般健檢平衡版',
                'scenario' => 'balanced',
                'tone' => 'neutral',
                'section_order' => ['price_theme', 'technical', 'chip', 'fundamental', 'summary'],
                'opening_template' => '{stock_name}目前需要從股價、技術、籌碼與基本面一起看，單一訊號不足以下結論。',
                'closing_template' => '比較可靠的判斷，是等待多個條件同向，而不是被單日漲跌牽著走。',
                'style_rules' => ['avoid_buy_sell_words' => true, 'avoid_repeated_phrases' => true],
                'selection_rules' => ['scenario' => 'balanced'],
                'weight' => 78,
            ],
        ];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
