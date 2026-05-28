<?php

namespace App\Console\Commands;

use App\Models\AgentMemory;
use App\Models\AgentRole;
use Illuminate\Console\Command;

class SeedAgentKnowledge extends Command
{
    protected $signature = 'market:agents-seed-knowledge {--reset : Deactivate previous v1 memories before seeding}';

    protected $description = 'Seed MarketX agent knowledge memories for rule-based and local-AI inspections.';

    public function handle(): int
    {
        if ($this->option('reset')) {
            AgentMemory::query()
                ->where('memory_type', 'like', 'knowledge:%')
                ->update(['status' => 'inactive']);
        }

        $roles = AgentRole::query()->pluck('id', 'slug');
        $count = 0;

        foreach ($this->knowledgePack() as $memory) {
            $roleId = $roles[$memory['role']] ?? $roles['learning-recorder'] ?? null;

            AgentMemory::query()->updateOrCreate(
                [
                    'memory_type' => $memory['type'],
                    'title' => $memory['title'],
                ],
                [
                    'agent_role_id' => $roleId,
                    'status' => 'active',
                    'rule_summary' => $memory['summary'],
                    'correct_pattern' => $memory['correct'],
                    'wrong_pattern' => $memory['wrong'],
                    'codex_feedback' => $memory['feedback'],
                    'confidence' => $memory['confidence'] ?? 85,
                    'examples' => $memory['examples'] ?? null,
                    'payload' => [
                        'version' => 'v1',
                        'category' => $memory['category'],
                        'signals' => $memory['signals'] ?? [],
                    ],
                ],
            );

            $count++;
        }

        $this->info('Agent knowledge seeded: '.$count);

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function knowledgePack(): array
    {
        return [
            [
                'role' => 'stock-consistency',
                'type' => 'knowledge:technical',
                'category' => 'technical_bull',
                'title' => '有效多方技術訊號需要趨勢與量能互相支持',
                'summary' => '多方訊號不能只看單一黃金交叉；較可靠的偏多條件是短中期均線、MACD/KD、RSI、量價與突破訊號同向。',
                'correct' => '站上月線、月線高於季線、MACD 多方或翻正、KD 黃金交叉、RSI 約 55 到 72，且成交量溫和放大。',
                'wrong' => '只有 KD 或 MACD 單一交叉，但股價仍在月線下、量能不足、或乖離過大，不能當作強多。',
                'feedback' => '代理人看到優先觀察或潛力觀察時，應檢查多方訊號是否至少有兩類以上互相支持。',
                'signals' => ['sma20', 'sma60', 'macd', 'kd', 'rsi14', 'volume_ratio20', 'breakout20'],
            ],
            [
                'role' => 'home-radar',
                'type' => 'knowledge:technical',
                'category' => 'technical_risk',
                'title' => '漲多風險要看乖離、過熱與量價鈍化',
                'summary' => '風險升高不是看到上漲就列入，而是上漲後出現過熱、量價背離、上影線、MACD 正數縮減或法人轉賣等證據。',
                'correct' => '20日乖離偏大、RSI 過熱、KD 過熱、MACD 正數縮減、放量收黑或上影線明顯，且至少伴隨籌碼或營收面疑慮。',
                'wrong' => '股價剛轉強、量價同步、法人仍買超時，不應只因短線漲幅就列入風險升高。',
                'feedback' => '首頁分類員要特別抓出「正常轉強卻被放到風險升高」的股票。',
                'signals' => ['bais20', 'rsi14', 'k9', 'macd_histogram', 'volume_multiple', 'institutional_net_buy'],
            ],
            [
                'role' => 'home-radar',
                'type' => 'knowledge:technical',
                'category' => 'low_base_volume',
                'title' => '低檔爆量分成低檔轉強與跌深反彈兩種',
                'summary' => '低檔爆量可能是長期盤整後轉強，也可能是短期或長期下跌後的跌深反彈；兩者都需要低位階、量能放大與收盤上漲。',
                'correct' => '股價低於或剛站回中長均線、20/60日報酬偏低或長期盤整，成交量明顯高於前日或20日均量，收盤上漲且非高檔過熱。',
                'wrong' => '高檔放量、收黑、只有量放大沒有上漲，或題材退潮中的放量下跌，不能稱為低檔爆量。',
                'feedback' => '低檔爆量卡應優先檢查 price position，再看 volume，再看 close direction。',
                'confidence' => 92,
                'signals' => ['sma60', 'sma120', 'return20', 'return60', 'volume_multiple', 'volume_ratio20', 'change_pct'],
            ],
            [
                'role' => 'stock-consistency',
                'type' => 'knowledge:chip',
                'category' => 'chip_bull',
                'title' => '籌碼偏多要看法人買超是否與股價同向',
                'summary' => '三大法人買超、外資投信同步買超是偏多訊號，但若股價不漲或放量收黑，可能代表有賣壓承接問題。',
                'correct' => '法人買超占成交量有意義，外資與投信方向一致，股價同步上漲或守住關鍵均線。',
                'wrong' => '法人買超但股價收黑、爆量不漲、或融資同時快速增加，不能單純加分。',
                'feedback' => '個股一致性員要抓出「籌碼看似偏多但價格沒有確認」的矛盾。',
                'signals' => ['foreign_net_buy', 'investment_trust_net_buy', 'institutional_net_buy', 'close', 'volume'],
            ],
            [
                'role' => 'home-radar',
                'type' => 'knowledge:chip',
                'category' => 'chip_risk',
                'title' => '融資快速增加會放大拉回風險',
                'summary' => '融資增加不一定立即看空，但若搭配高檔、過熱、法人轉賣或股價轉弱，就會放大籌碼鬆動風險。',
                'correct' => '股價漲多或高檔震盪時，融資快速增加、法人賣超、券資比偏高，應列為風險提醒。',
                'wrong' => '低檔剛轉強且法人買盤延續時，融資小幅增加不應直接視為主要風險。',
                'feedback' => '風險升高卡不能只有融資偏重，需搭配技術或法人訊號。',
                'signals' => ['margin_balance', 'short_balance', 'institutional_net_buy', 'bais20', 'return20'],
            ],
            [
                'role' => 'stock-consistency',
                'type' => 'knowledge:fundamental',
                'category' => 'fundamental_bull',
                'title' => '營收與財報是信心分數的底層支撐',
                'summary' => '月營收年增、月增、EPS、ROE、毛利率與合理本益比，是個股看多信心的重要底層依據。',
                'correct' => '營收年增或月增轉強、EPS 為正、ROE 良好、毛利率不惡化，本益比未明顯過高。',
                'wrong' => '只有題材或技術轉強，但營收年減擴大、EPS 虧損或本益比過高，不應給過高信心。',
                'feedback' => '個股評價要避免只被技術與題材拉高，財務不支撐時應標記資料風險。',
                'signals' => ['yoy_pct', 'mom_pct', 'eps', 'roe', 'gross_margin', 'per'],
            ],
            [
                'role' => 'home-radar',
                'type' => 'knowledge:fundamental',
                'category' => 'valuation_risk',
                'title' => '高本益比需要成長或題材延續支撐',
                'summary' => '本益比高不是一定不好，但若成長放緩、題材降溫或法人轉賣，高估值會使拉回風險提高。',
                'correct' => '高 PER 搭配營收高成長與題材升溫，可以觀察；高 PER 搭配營收轉弱或法人賣超，應列風險。',
                'wrong' => '單看 PER 高就判定風險，或忽略高成長股合理溢價，都會失真。',
                'feedback' => '代理人評估評價時，要同時看 growth、theme heat、chip direction。',
                'signals' => ['per', 'yoy_pct', 'mom_pct', 'theme_score', 'institutional_net_buy'],
            ],
            [
                'role' => 'theme-radar',
                'type' => 'knowledge:theme',
                'category' => 'theme_heat',
                'title' => '題材熱度是輔助分數，不應壓過基本證據',
                'summary' => '題材熱度來自新聞、代表股、量價與籌碼，但它是輔助，不應單獨決定個股信心或首頁分類。',
                'correct' => '題材升溫同時有新聞事件、代表股轉強、量價同步與法人延續，可信度較高。',
                'wrong' => '只有新聞多、沒有代表股走勢支撐，或只有少數個股上漲，不能說族群全面升溫。',
                'feedback' => '題材雷達員要檢查題材是否擴散，以及代表股是否真的支撐熱度。',
                'signals' => ['news_score', 'price_score', 'volume_score', 'chip_score', 'representative_stocks'],
            ],
            [
                'role' => 'theme-radar',
                'type' => 'knowledge:theme',
                'category' => 'theme_rotation',
                'title' => '資金輪動要看前一日台股與昨晚國際市場',
                'summary' => '題材盤前判斷要結合台股收盤後資料、美股族群、ADR、台指夜盤與新聞事件，不應只看固定題材榜單。',
                'correct' => '昨晚美股半導體或記憶體上漲、ADR 同步、台指夜盤偏強，且台股代表股昨日量價轉強，可列為今日可觀察族群。',
                'wrong' => '引用舊新聞、忽略昨晚市場，或明明代表股仍強卻說降溫。',
                'feedback' => '題材分析應明確寫出人事時地物與市場依據，不能格式化套話。',
                'signals' => ['global_market', 'taifex_night', 'event_clusters', 'theme_scores'],
            ],
            [
                'role' => 'global-radar',
                'type' => 'knowledge:global',
                'category' => 'global_context',
                'title' => '全球盤前要拆成股市、利率匯率、商品與事件',
                'summary' => '全球雷達分析需分別觀察美股科技、半導體、亞洲股市、美元、十年債、油金與重大事件，再推論對台股的外部環境。',
                'correct' => '美股半導體與 ADR 強、美元與債息壓力下降、台指夜盤偏強，通常有利台股風險偏好；反之需提醒壓力。',
                'wrong' => '只寫全球偏強或偏弱，沒有說明哪個市場、指數、商品或事件造成影響。',
                'feedback' => '全球雷達員要檢查 AI 報告是否有數據依據與清楚段落。',
                'signals' => ['SOX', 'NASDAQ', 'TSM ADR', 'UMC ADR', 'DXY', 'US10Y', 'Crude Oil', 'Gold'],
            ],
            [
                'role' => 'data-quality',
                'type' => 'knowledge:data',
                'category' => 'freshness',
                'title' => '資料更新時間要與用途一致',
                'summary' => '盤後、晚間補抓、盤前分析使用的資料更新時間不同，代理人要檢查資料是否符合該頁面的使用場景。',
                'correct' => '首頁分類使用最新台股價格、技術、籌碼與財務；全球雷達使用近期全球市場資料；AI 盤前報告使用當日或最新可得資料。',
                'wrong' => '台股價格已更新但技術或 stock_scores 還停在前一日，或 AI 報告使用舊全球資料。',
                'feedback' => '資料品質員每天要先檢查 freshness，再判斷前端問題。',
                'confidence' => 90,
                'signals' => ['stock_prices_1d', 'stock_technical_indicators_1d', 'stock_scores', 'global_market_data', 'ai_reports'],
            ],
        ];
    }
}
