<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedReportPhrases extends Command
{
    protected $signature = 'market:seed-report-phrases {--reset : Delete existing manual active phrases before seeding}';

    protected $description = 'Seed the manual Chinese phrase library used by rule-based stock reports.';

    public function handle(): int
    {
        if ($this->option('reset')) {
            DB::table('report_phrases')->where('source', 'manual')->delete();
        }

        $now = now();
        $inserted = 0;

        DB::table('report_phrases')
            ->whereIn('template', $this->deprecatedTemplates())
            ->update([
                'status' => 'inactive',
                'updated_at' => $now,
            ]);

        foreach ($this->phrases() as $phrase) {
            $payload = [
                'section' => $phrase['section'],
                'tone' => $phrase['tone'],
                'condition_key' => $phrase['condition_key'],
                'template' => $phrase['template'],
                'weight' => $phrase['weight'] ?? 50,
                'source' => 'manual',
                'status' => 'active',
                'metadata' => json_encode(['seed' => 'manual_v1'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $exists = DB::table('report_phrases')
                ->where('section', $payload['section'])
                ->where('condition_key', $payload['condition_key'])
                ->where('template', $payload['template'])
                ->exists();

            if (! $exists) {
                DB::table('report_phrases')->insert($payload);
                $inserted++;
            }
        }

        $this->info('Report phrases seeded: '.$inserted);
        $this->line('Total active phrases: '.DB::table('report_phrases')->where('status', 'active')->count());

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{section:string,tone:string,condition_key:string,template:string,weight?:int}>
     */
    private function phrases(): array
    {
        return array_merge(
            $this->pricePhrases(),
            $this->technicalPhrases(),
            $this->chipPhrases(),
            $this->fundamentalPhrases(),
            $this->summaryPhrases(),
        );
    }

    private function pricePhrases(): array
    {
        return [
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'theme_hot_price_up', 'template' => '{stock_name}近期股價跟著{theme_text}題材升溫，價格表現明顯比一般個股更有焦點，代表市場正在把題材想像反映到股價上。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'theme_hot_price_up', 'template' => '從走勢來看，{stock_name}這波上攻並不是單純技術反彈，背後也有{theme_text}熱度支撐，短線資金關注度偏高。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'theme_hot_price_up', 'template' => '{theme_text}仍是市場討論主軸之一，若代表股續強，{stock_name}容易被資金放在同一族群內觀察。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'price_up_volume_up', 'template' => '{stock_name}近期股價上漲時量能同步放大，表示追價與換手意願都有增加，走勢比無量上漲更有支撐。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'price_up_volume_up', 'template' => '價量結構目前偏正向，股價推升時成交量跟上，代表市場不是只有少量資金拉抬。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'price_up_volume_flat', 'template' => '{stock_name}雖然股價有表現，但量能沒有明顯同步放大，後續要觀察追價買盤是否延續。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'price_up_volume_flat', 'template' => '目前股價偏強，但成交量配合度普通，走勢比較像溫和墊高，還不到資金全面進攻的狀態。'],
            ['section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'price_extended', 'template' => '{stock_name}近 20 日漲幅與乖離率都偏高，現在不是單純看強不強，而是要看成交量、法人買盤與營收能不能接住已反映的期待。'],
            ['section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'price_extended', 'template' => '股價目前離短期均線偏遠，市場期待已經反映不少，追高時要留意震盪放大的可能。'],
            ['section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'price_extended', 'template' => '這類走勢最怕題材熱度降溫後買盤縮手，若量能退潮，股價容易先回測支撐。'],
            ['section' => 'price_theme', 'tone' => 'bear', 'condition_key' => 'price_down_volume_up', 'template' => '{stock_name}下跌時量能放大，代表賣壓不是零星出現，短線需要先觀察是否止跌。'],
            ['section' => 'price_theme', 'tone' => 'bear', 'condition_key' => 'price_down_volume_up', 'template' => '股價轉弱搭配成交量放大，通常代表籌碼換手壓力升高，短線不宜只用跌深反彈解讀。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'theme_missing', 'template' => '{stock_name}目前沒有明確接上高熱度題材，股價表現主要仍要回到技術、籌碼與財務條件判斷。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'theme_missing', 'template' => '題材面暫時不是{stock_name}的主要支撐，後續若新聞或族群擴散增加，評價才比較容易重新被市場注意。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'price_sideways', 'template' => '{stock_name}近期股價偏整理，市場還沒有明確表態，較適合觀察量能是否先行變化。'],
            ['section' => 'price_theme', 'tone' => 'neutral', 'condition_key' => 'price_sideways', 'template' => '目前價格走勢沒有明顯單邊方向，若後續突破整理區且量能放大，訊號才會更清楚。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'low_base_breakout', 'template' => '{stock_name}有低檔轉強跡象，若這次放量能守住收盤價位，後續有機會從整理格局轉為重新被市場關注。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'low_base_breakout', 'template' => '股價從相對低位階放量上來，這種型態重點不是追高，而是觀察量能是否能連續、回檔是否守得住。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'today_rebound_after_drop', 'template' => '{stock_name}近 20 日仍是下跌或整理格局，但今天股價轉強且量能放大到約 {volume_ratio20}，比較像資金開始試探低位階反彈。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'today_rebound_after_drop', 'template' => '{stock_name}前段走勢偏弱，今天能在放量下收紅，重點會從「跌深」轉成觀察是否能連續站回短期均線。'],
            ['section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'today_pullback_after_run', 'template' => '{stock_name}近 20 日漲幅約 {return20}，今天轉為拉回，代表前波追價買盤開始遇到壓力，短線要看回檔是否仍守住均線。'],
            ['section' => 'price_theme', 'tone' => 'risk', 'condition_key' => 'today_pullback_after_run', 'template' => '這檔不是單純弱勢，而是前面已經漲過一段後出現降溫，若量能跟著放大，會比較像獲利了結壓力。'],
            ['section' => 'price_theme', 'tone' => 'bear', 'condition_key' => 'recent_downtrend', 'template' => '{stock_name}近 20 日報酬約 {return20}、近 60 日約 {return60}，近期走勢仍偏弱，現在要先看止跌訊號，而不是急著用反彈解讀。'],
            ['section' => 'price_theme', 'tone' => 'bear', 'condition_key' => 'recent_downtrend', 'template' => '近期股價重心還在往下，除非今天的轉強能延續成連續收復均線，否則仍屬於修復中的走勢。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'recent_momentum', 'template' => '{stock_name}近 5 日漲幅約 {return5}、近 20 日約 {return20}，短線動能明顯，若量能沒有快速退潮，市場關注度仍會維持。'],
            ['section' => 'price_theme', 'tone' => 'bull', 'condition_key' => 'recent_momentum', 'template' => '最近幾個交易日股價重心明顯墊高，這種走勢通常代表資金還沒有完全退場，但也要留意乖離率約 {bais20} 帶來的震盪。'],
        ];
    }

    private function technicalPhrases(): array
    {
        return [
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'ma_bull', 'template' => '均線結構偏多，股價位在短中期均線之上，代表市場平均成本正在往上墊高。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'ma_bull', 'template' => '短期均線逐步走揚，若股價回測不跌破關鍵均線，多方結構仍可維持。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'macd_bull', 'template' => 'MACD 動能偏多，若柱狀體持續擴大，代表上攻力道仍在增加。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'macd_bull', 'template' => 'MACD 仍在多方排列時，要搭配柱狀體是否續增判斷；若價漲但柱狀體縮小，就要小心動能開始鈍化。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'kd_golden', 'template' => 'KD 出現偏多交叉，短線買盤有重新轉強跡象，但仍需搭配成交量確認。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'rsi_strong', 'template' => 'RSI 位在強勢區，代表買盤動能不弱，但若快速接近過熱區，追價風險也會提高。'],
            ['section' => 'technical', 'tone' => 'bull', 'condition_key' => 'breakout20', 'template' => '股價有突破近期壓力的訊號，若突破後沒有快速跌回，技術面會比整理期更有利。'],
            ['section' => 'technical', 'tone' => 'neutral', 'condition_key' => 'technical_mixed', 'template' => '技術面目前多空訊號混雜，還不能只用單一指標下結論，最好搭配量價與籌碼一起看。'],
            ['section' => 'technical', 'tone' => 'neutral', 'condition_key' => 'technical_mixed', 'template' => '部分指標偏多、部分指標仍在整理，代表股價還在等待更明確的方向選擇。'],
            ['section' => 'technical', 'tone' => 'risk', 'condition_key' => 'bais_high', 'template' => '乖離率偏高，短線股價離平均成本過遠，若買盤稍微降溫就容易拉回修正。'],
            ['section' => 'technical', 'tone' => 'risk', 'condition_key' => 'bais_high', 'template' => '目前不是沒有強勢，而是強勢後的安全邊際變小，回檔測試均線反而是重要觀察點。'],
            ['section' => 'technical', 'tone' => 'risk', 'condition_key' => 'rsi_overheat', 'template' => 'RSI 已接近或進入過熱區，代表短線情緒偏亢奮，容易出現震盪加劇。'],
            ['section' => 'technical', 'tone' => 'risk', 'condition_key' => 'macd_shrinking', 'template' => 'MACD 雖然仍可能在正值區，但動能已開始縮減，代表上攻速度有放慢跡象。'],
            ['section' => 'technical', 'tone' => 'risk', 'condition_key' => 'upper_shadow', 'template' => 'K 線上影線偏明顯，表示高檔有賣壓出現，短線要觀察隔日能否重新站回高點附近。'],
            ['section' => 'technical', 'tone' => 'bear', 'condition_key' => 'ma_bear', 'template' => '均線結構偏弱，股價仍受短中期均線壓制，反彈若無法站回均線，容易只是技術性修正。'],
            ['section' => 'technical', 'tone' => 'bear', 'condition_key' => 'macd_bear', 'template' => 'MACD 偏空，動能尚未明顯翻正，短線需要先看到跌勢收斂。'],
            ['section' => 'technical', 'tone' => 'bear', 'condition_key' => 'kd_dead', 'template' => 'KD 轉弱代表短線買盤退潮，若股價同時跌破均線，技術壓力會加重。'],
            ['section' => 'technical', 'tone' => 'bear', 'condition_key' => 'below_sma20', 'template' => '收盤仍在月線下方，短線趨勢尚未轉強，月線會是第一個需要收復的位置。'],
            ['section' => 'technical', 'tone' => 'bear', 'condition_key' => 'rsi_weak', 'template' => 'RSI 處於弱勢區，代表反彈力道不足，買盤還沒有明顯掌握節奏。'],
            ['section' => 'technical', 'tone' => 'neutral', 'condition_key' => 'volume_wait', 'template' => '技術面目前最需要觀察量能，如果價格突破但量能不足，訊號可靠度會打折。'],
        ];
    }

    private function chipPhrases(): array
    {
        return [
            ['section' => 'chip', 'tone' => 'bull', 'condition_key' => 'foreign_trust_buy', 'template' => '籌碼面外資與投信同步偏買，代表法人方向較一致，對股價有一定支撐。'],
            ['section' => 'chip', 'tone' => 'bull', 'condition_key' => 'foreign_trust_buy', 'template' => '外資與投信同向買超時，通常代表資金不是單一來源短線進出，籌碼穩定度相對較好。'],
            ['section' => 'chip', 'tone' => 'bull', 'condition_key' => 'institutional_buy', 'template' => '三大法人合計買超，顯示法人資金仍願意承接，短線籌碼不算弱。'],
            ['section' => 'chip', 'tone' => 'bull', 'condition_key' => 'institutional_buy', 'template' => '法人買盤若能連續，股價回檔時比較容易出現承接力道。'],
            ['section' => 'chip', 'tone' => 'neutral', 'condition_key' => 'chip_neutral', 'template' => '籌碼面目前沒有明顯單邊方向，股價後續比較容易由技術面與題材熱度主導。'],
            ['section' => 'chip', 'tone' => 'neutral', 'condition_key' => 'chip_neutral', 'template' => '法人買賣沒有形成一致趨勢，短線資金態度仍偏觀望。'],
            ['section' => 'chip', 'tone' => 'risk', 'condition_key' => 'margin_high', 'template' => '融資水位偏高，若股價轉弱，散戶槓桿籌碼可能放大波動。'],
            ['section' => 'chip', 'tone' => 'risk', 'condition_key' => 'margin_high', 'template' => '融資增加不一定立刻看空，但若搭配高檔或法人轉賣，就容易形成籌碼鬆動風險。'],
            ['section' => 'chip', 'tone' => 'risk', 'condition_key' => 'short_high', 'template' => '融券或放空相關水位偏高，代表市場分歧加大，股價波動可能比一般狀況更劇烈。'],
            ['section' => 'chip', 'tone' => 'risk', 'condition_key' => 'chip_divergence', 'template' => '股價表現與法人籌碼沒有同步，若價格續漲但法人不跟，後續要留意買盤續航力。'],
            ['section' => 'chip', 'tone' => 'bear', 'condition_key' => 'foreign_trust_sell', 'template' => '外資與投信同步偏賣，代表法人籌碼轉弱，短線反彈需要更強的量能才能扭轉。'],
            ['section' => 'chip', 'tone' => 'bear', 'condition_key' => 'foreign_trust_sell', 'template' => '主要法人同向賣超時，市場容易先把它解讀為資金撤出，股價壓力會比較明顯。'],
            ['section' => 'chip', 'tone' => 'bear', 'condition_key' => 'institutional_sell', 'template' => '三大法人合計賣超，代表資金面偏保守，短線要避免只看技術反彈。'],
            ['section' => 'chip', 'tone' => 'bear', 'condition_key' => 'institutional_sell', 'template' => '法人賣壓若連續出現，股價即使短彈也容易遇到解套或調節壓力。'],
            ['section' => 'chip', 'tone' => 'neutral', 'condition_key' => 'data_missing', 'template' => '籌碼資料尚未完整，這一段先以已揭露的法人與信用交易資料觀察，不宜過度解讀。'],
        ];
    }

    private function fundamentalPhrases(): array
    {
        return [
            ['section' => 'fundamental', 'tone' => 'bull', 'condition_key' => 'revenue_yoy_strong', 'template' => '營收年增率明顯成長，代表基本面仍有擴張動能，較能支撐市場對股價的期待。'],
            ['section' => 'fundamental', 'tone' => 'bull', 'condition_key' => 'revenue_yoy_strong', 'template' => '最新月營收年增表現不錯，若後續能延續，題材就比較不會只是短線想像。'],
            ['section' => 'fundamental', 'tone' => 'bull', 'condition_key' => 'revenue_mom_strong', 'template' => '月營收較前月改善，短期營運動能有回升跡象。'],
            ['section' => 'fundamental', 'tone' => 'bull', 'condition_key' => 'profit_quality_good', 'template' => 'EPS、ROE 或毛利率表現相對穩定，代表公司獲利品質並不差。'],
            ['section' => 'fundamental', 'tone' => 'neutral', 'condition_key' => 'fundamental_stable', 'template' => '財務營收目前屬於穩定狀態，沒有明顯拖累，但也還不是推升股價的最強因素。'],
            ['section' => 'fundamental', 'tone' => 'neutral', 'condition_key' => 'fundamental_stable', 'template' => '基本面暫時沒有太極端的訊號，後續重點會放在營收能否延續，以及評價是否合理。'],
            ['section' => 'fundamental', 'tone' => 'risk', 'condition_key' => 'per_high', 'template' => '本益比偏高，代表市場已經給較高期待；若營收或獲利跟不上，評價修正壓力會升高。'],
            ['section' => 'fundamental', 'tone' => 'risk', 'condition_key' => 'per_high', 'template' => '高評價不是問題本身，問題在於成長是否能配得上目前價格，這會是後續觀察重點。'],
            ['section' => 'fundamental', 'tone' => 'risk', 'condition_key' => 'pb_high', 'template' => '股價淨值比偏高，市場給予較高溢價，若題材退潮，股價容易對壞消息更敏感。'],
            ['section' => 'fundamental', 'tone' => 'bear', 'condition_key' => 'revenue_yoy_weak', 'template' => '營收年增率轉弱，代表基本面支撐力下降，若股價仍在高位，風險會比較明顯。'],
            ['section' => 'fundamental', 'tone' => 'bear', 'condition_key' => 'revenue_yoy_weak', 'template' => '最新營收較去年同期衰退，短線即使有題材，也需要小心基本面跟不上股價。'],
            ['section' => 'fundamental', 'tone' => 'bear', 'condition_key' => 'revenue_mom_weak', 'template' => '月營收較前月下滑，短期營運動能偏弱，後續要觀察是否只是季節性因素。'],
            ['section' => 'fundamental', 'tone' => 'neutral', 'condition_key' => 'dividend_available', 'template' => '股利政策可作為長期穩定度參考，但短線股價仍主要受營收、籌碼與題材影響。'],
            ['section' => 'fundamental', 'tone' => 'neutral', 'condition_key' => 'fundamental_missing', 'template' => '財務資料尚未完整，暫時不宜用單一估值指標判斷公司基本面。'],
            ['section' => 'fundamental', 'tone' => 'risk', 'condition_key' => 'price_fundamental_gap', 'template' => '股價漲幅與營收成長出現落差，若沒有新的獲利證據，市場可能重新檢查估值合理性。'],
        ];
    }

    private function summaryPhrases(): array
    {
        return [
            ['section' => 'summary', 'tone' => 'bull', 'condition_key' => 'overall_bull', 'template' => '整體來看，{stock_name}偏向多方觀察，但重點要分清楚是剛轉強、延續強勢，還是已經漲多；不同位階的風險完全不同。'],
            ['section' => 'summary', 'tone' => 'bull', 'condition_key' => 'overall_bull', 'template' => '{stock_name}目前條件相對完整，若量能與法人買盤延續，股價較有機會維持強勢整理或續攻。'],
            ['section' => 'summary', 'tone' => 'bull', 'condition_key' => 'overall_bull', 'template' => '總評偏正向，但操作上仍應以關鍵均線與量能是否延續作為風險控管依據。'],
            ['section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'overall_watch', 'template' => '整體來看，{stock_name}屬於觀察名單，不是明顯弱勢，但也還沒到所有條件都同步轉強。'],
            ['section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'overall_watch', 'template' => '目前較適合等待訊號確認，若題材、量能與籌碼能進一步配合，評價才會更有說服力。'],
            ['section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'overall_watch', 'template' => '總評維持中性偏觀察，重點在於後續能否補上量能、法人或營收其中一項確認訊號。'],
            ['section' => 'summary', 'tone' => 'risk', 'condition_key' => 'overall_risk', 'template' => '{stock_name}目前最大的問題不是沒有題材，而是股價期待與基本面、籌碼或技術風險之間需要重新平衡。'],
            ['section' => 'summary', 'tone' => 'risk', 'condition_key' => 'overall_risk', 'template' => '總評偏向風險觀察，若後續沒有新的營收或法人買盤支撐，股價容易進入震盪或拉回。'],
            ['section' => 'summary', 'tone' => 'risk', 'condition_key' => 'overall_risk', 'template' => '目前要留意高檔震盪、量能退潮或法人調節，這些訊號會比單日漲跌更重要。'],
            ['section' => 'summary', 'tone' => 'bear', 'condition_key' => 'overall_bear', 'template' => '整體來看，{stock_name}目前偏弱，技術與籌碼尚未明顯修復，短線應先觀察止跌訊號。'],
            ['section' => 'summary', 'tone' => 'bear', 'condition_key' => 'overall_bear', 'template' => '總評偏保守，若股價無法站回短期均線，反彈仍容易被視為整理過程。'],
            ['section' => 'summary', 'tone' => 'bear', 'condition_key' => 'overall_bear', 'template' => '目前不是適合只看便宜的位置，還需要看到量能、籌碼或基本面至少一項轉強。'],
            ['section' => 'summary', 'tone' => 'risk', 'condition_key' => 'data_limited', 'template' => '由於部分資料仍不完整，本次評價以既有官方資料與技術籌碼訊號為主，結論需保留彈性。'],
            ['section' => 'summary', 'tone' => 'neutral', 'condition_key' => 'wait_for_confirmation', 'template' => '後續觀察重點是成交量能否延續、法人是否站在買方，以及營收是否能跟上股價期待。'],
            ['section' => 'summary', 'tone' => 'risk', 'condition_key' => 'invalid_condition', 'template' => '若股價跌破關鍵均線、量能退潮或法人連續轉賣，原本偏多條件就需要重新評估。'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function deprecatedTemplates(): array
    {
        return [
            '{stock_name}短線漲幅已經拉開，若後續題材或營收沒有新的支撐，容易出現漲多整理。',
            'MACD 目前站在相對有利的位置，短線只要不快速翻弱，趨勢仍有延續空間。',
            '整體來看，{stock_name}目前偏向多方觀察，支撐來自技術、籌碼或題材至少兩項同向。不過仍要避免在短線急漲後追價過深。',
        ];
    }
}
