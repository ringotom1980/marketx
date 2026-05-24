<?php

namespace App\Support;

use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChipSignalAnalyzer
{
    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\StockChip1d> $recentChips
     * @param \Illuminate\Support\Collection<int, \App\Models\StockPrice1d> $recentPrices
     * @return array<int, array{tone: string, title: string, body: string}>
     */
    public function analyze(Stock $stock, Collection $recentChips, Collection $recentPrices): array
    {
        $latestChip = $recentChips->first();

        if (! $latestChip) {
            return [[
                'tone' => 'amber',
                'title' => '籌碼資料不足',
                'body' => '目前尚未匯入法人或融資融券資料，等待每日資料更新後產生籌碼判讀。',
            ]];
        }

        $previousChip = $recentChips->skip(1)->first();
        $latestPrice = $recentPrices->first();
        $previousPrice = $recentPrices->skip(1)->first();
        $volume = max(1, (int) ($latestPrice?->volume ?? 1));
        $avgVolume20 = $this->average($recentPrices->pluck('volume')->filter(fn ($value) => $value !== null)->take(20)->all());
        $priceChange = $latestPrice && $previousPrice
            ? (float) $latestPrice->close - (float) $previousPrice->close
            : null;

        $signals = [];
        $institutionalRatio = ((int) ($latestChip->institutional_net_buy ?? 0)) / $volume;
        $foreignRatio = ((int) ($latestChip->foreign_net_buy ?? 0)) / $volume;
        $trustRatio = ((int) ($latestChip->investment_trust_net_buy ?? 0)) / $volume;
        $dealerRatio = ((int) ($latestChip->dealer_net_buy ?? 0)) / $volume;

        if ($institutionalRatio >= 0.08) {
            $signals[] = ['tone' => 'green', 'title' => '三大法人明顯買超', 'body' => '三大法人買超占成交量比重偏高，籌碼短線偏向集中。'];
        } elseif ($institutionalRatio <= -0.08) {
            $signals[] = ['tone' => 'red', 'title' => '三大法人明顯賣超', 'body' => '三大法人賣超占成交量比重偏高，短線籌碼承壓。'];
        } elseif ($latestChip->institutional_net_buy > 0) {
            $signals[] = ['tone' => 'green', 'title' => '法人小幅買超', 'body' => '三大法人合計偏買方，但力道尚未明顯放大。'];
        } elseif ($latestChip->institutional_net_buy < 0) {
            $signals[] = ['tone' => 'red', 'title' => '法人小幅賣超', 'body' => '三大法人合計偏賣方，短線需觀察是否連續調節。'];
        }

        if ($latestChip->foreign_net_buy > 0 && $latestChip->investment_trust_net_buy > 0) {
            $signals[] = ['tone' => 'green', 'title' => '外資與投信同步買超', 'body' => '外資和投信站在同一邊，籌碼方向較容易形成共識。'];
        } elseif ($latestChip->foreign_net_buy < 0 && $latestChip->investment_trust_net_buy < 0) {
            $signals[] = ['tone' => 'red', 'title' => '外資與投信同步賣超', 'body' => '外資和投信同步調節，需留意波段資金撤出。'];
        } elseif ($latestChip->foreign_net_buy > 0 && $latestChip->investment_trust_net_buy < 0) {
            $signals[] = ['tone' => 'amber', 'title' => '外資買、投信賣', 'body' => '法人方向分歧，籌碼尚未形成一致推力。'];
        } elseif ($latestChip->foreign_net_buy < 0 && $latestChip->investment_trust_net_buy > 0) {
            $signals[] = ['tone' => 'amber', 'title' => '投信買、外資賣', 'body' => '投信承接但外資調節，需觀察誰的力道延續。'];
        }

        $recentInstitutionalSum = (int) $recentChips->take(3)->sum('institutional_net_buy');
        if ($recentInstitutionalSum > 0 && $latestChip->institutional_net_buy > 0) {
            $signals[] = ['tone' => 'green', 'title' => '法人連續偏買', 'body' => '近 3 筆法人合計仍偏買方，資金籌碼有延續跡象。'];
        } elseif ($recentInstitutionalSum < 0 && $latestChip->institutional_net_buy < 0) {
            $signals[] = ['tone' => 'red', 'title' => '法人連續偏賣', 'body' => '近 3 筆法人合計仍偏賣方，籌碼尚未止穩。'];
        }

        if (max(abs($foreignRatio), abs($trustRatio), abs($dealerRatio)) >= 0.12) {
            $signals[] = ['tone' => $institutionalRatio >= 0 ? 'green' : 'red', 'title' => '資金集中度高', 'body' => '單一法人買賣超占成交量比重偏高，短線容易被主力資金方向牽動。'];
        }

        if ($latestChip->margin_balance !== null && $latestChip->short_balance !== null && $latestChip->margin_balance > 0) {
            $shortMarginRatio = $latestChip->short_balance / $latestChip->margin_balance;

            if ($shortMarginRatio >= 0.6) {
                $signals[] = ['tone' => 'amber', 'title' => '券資比過高', 'body' => '融券餘額相對融資偏高，若股價轉強容易軋空，但若轉弱也代表市場分歧很大。'];
            } elseif ($shortMarginRatio >= 0.3) {
                $signals[] = ['tone' => 'amber', 'title' => '券資比偏高', 'body' => '空方籌碼比重不低，短線波動可能放大。'];
            } elseif ($shortMarginRatio < 0.08) {
                $signals[] = ['tone' => 'green', 'title' => '券資比偏低', 'body' => '融券壓力不高，空方回補推力相對有限。'];
            }
        }

        if ($latestChip->margin_balance !== null && $previousChip?->margin_balance !== null) {
            $marginChange = $latestChip->margin_balance - $previousChip->margin_balance;

            if ($marginChange > 0 && $priceChange !== null && $priceChange < 0) {
                $signals[] = ['tone' => 'amber', 'title' => '融資增加但股價下跌', 'body' => '散戶融資加碼但價格轉弱，短線賣壓風險升高。'];
            } elseif ($marginChange > 0 && $priceChange !== null && $priceChange > 0) {
                $signals[] = ['tone' => 'amber', 'title' => '融資隨股價增加', 'body' => '上漲過程中融資同步增加，代表人氣升溫，但也要留意籌碼變重。'];
            } elseif ($marginChange < 0 && $priceChange !== null && $priceChange >= 0) {
                $signals[] = ['tone' => 'green', 'title' => '融資下降股價穩住', 'body' => '融資減少但價格未轉弱，籌碼有整理乾淨的跡象。'];
            }
        }

        if ($latestChip->margin_balance !== null && $avgVolume20 > 0) {
            $marginVolumeRatio = $latestChip->margin_balance / $avgVolume20;

            if ($marginVolumeRatio >= 8) {
                $signals[] = ['tone' => 'amber', 'title' => '融資集中偏高', 'body' => '融資餘額相對近期成交量偏高，若跌破支撐容易引發停損賣壓。'];
            } elseif ($marginVolumeRatio <= 1.5) {
                $signals[] = ['tone' => 'green', 'title' => '融資負擔較輕', 'body' => '融資餘額相對成交量不高，籌碼壓力較小。'];
            }
        }

        $signals = array_merge($signals, $this->dayTradeSignals($stock, $latestPrice, $volume));

        return array_slice($signals, 0, 8);
    }

    /**
     * @return array<int, array{tone: string, title: string, body: string}>
     */
    private function dayTradeSignals(Stock $stock, mixed $latestPrice, int $volume): array
    {
        $latestDate = $latestPrice?->trade_date?->toDateString();

        if (! $latestDate) {
            return [[
                'tone' => 'amber',
                'title' => '隔日沖資料等待日 K',
                'body' => '尚未取得最新交易日，暫不判斷隔日沖券商分點。',
            ]];
        }

        $recentPatterns = DB::table('broker_daytrade_patterns')
            ->join('broker_branches', 'broker_branches.id', '=', 'broker_daytrade_patterns.broker_branch_id')
            ->where('broker_daytrade_patterns.stock_id', $stock->id)
            ->where(function ($query) use ($latestDate) {
                $query->where('broker_daytrade_patterns.buy_date', $latestDate)
                    ->orWhere('broker_daytrade_patterns.sell_date', $latestDate);
            })
            ->orderByDesc('broker_daytrade_patterns.sellback_ratio')
            ->limit(5)
            ->get([
                'broker_branches.name',
                'broker_branches.code',
                'broker_daytrade_patterns.buy_date',
                'broker_daytrade_patterns.sell_date',
                'broker_daytrade_patterns.buy_volume',
                'broker_daytrade_patterns.sell_volume',
                'broker_daytrade_patterns.sellback_ratio',
                'broker_daytrade_patterns.confidence_score',
            ]);

        if ($recentPatterns->isEmpty()) {
            $totalImported = DB::table('stock_broker_trades_1d')->where('stock_id', $stock->id)->count();

            if ($totalImported === 0) {
                return [[
                    'tone' => 'amber',
                    'title' => '尚未匯入券商分點資料',
                    'body' => '目前沒有此股的官方券商分點買賣日報資料，因此不產生隔日沖名單。',
                ]];
            }

            return [[
                'tone' => 'amber',
                'title' => '未偵測到明顯隔日沖分點',
                'body' => '已匯入的分點資料中，最新交易日沒有符合大量買進後快速回吐的模式。',
            ]];
        }

        $buyToday = $recentPatterns->where('buy_date', $latestDate);
        $sellToday = $recentPatterns->where('sell_date', $latestDate);
        $signals = [];

        if ($sellToday->isNotEmpty()) {
            $sellVolume = (int) $sellToday->sum('sell_volume');
            $ratio = $volume > 0 ? round(($sellVolume / $volume) * 100, 2) : null;
            $branches = $this->branchNames($sellToday);
            $signals[] = [
                'tone' => 'red',
                'title' => '隔日沖分點回吐賣壓',
                'body' => $branches.' 今日疑似回吐賣出，賣出量約占成交量 '.($ratio === null ? '無法估算' : $ratio.'%').'，短線賣壓需留意。',
            ];
        }

        if ($buyToday->isNotEmpty()) {
            $buyVolume = (int) $buyToday->sum('buy_volume');
            $ratio = $volume > 0 ? round(($buyVolume / $volume) * 100, 2) : null;
            $branches = $this->branchNames($buyToday);
            $signals[] = [
                'tone' => 'amber',
                'title' => '疑似隔日沖分點進場',
                'body' => $branches.' 今日疑似短線買超，買超量約占成交量 '.($ratio === null ? '無法估算' : $ratio.'%').'，隔日需觀察是否反手賣出。',
            ];
        }

        return $signals;
    }

    private function branchNames(Collection $patterns): string
    {
        return $patterns
            ->map(fn ($row) => ($row->name ?: '券商分點').'('.$row->code.')')
            ->unique()
            ->take(3)
            ->implode('、');
    }

    private function average(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));

        return count($values) === 0 ? 0.0 : array_sum($values) / count($values);
    }
}
