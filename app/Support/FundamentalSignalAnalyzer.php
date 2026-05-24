<?php

namespace App\Support;

use App\Models\Stock;

class FundamentalSignalAnalyzer
{
    /**
     * @return array<int, array{tone: string, title: string, body: string}>
     */
    public function analyze(Stock $stock, mixed $financial, mixed $revenue): array
    {
        if (! $financial && ! $revenue) {
            return [[
                'tone' => 'amber',
                'title' => '財報資料不足',
                'body' => '目前尚未取得財報或月營收資料，等待官方資料更新後產生分析。',
            ]];
        }

        $signals = [];

        if ($revenue?->yoy_pct !== null) {
            $yoy = (float) $revenue->yoy_pct;

            if ($yoy >= 20) {
                $signals[] = ['tone' => 'green', 'title' => '月營收年增強勁', 'body' => '最新月營收年增率達 '.round($yoy, 2).'%，營運動能明顯升溫。'];
            } elseif ($yoy >= 5) {
                $signals[] = ['tone' => 'green', 'title' => '月營收穩定成長', 'body' => '最新月營收年增率為 '.round($yoy, 2).'%，營收維持正成長。'];
            } elseif ($yoy <= -15) {
                $signals[] = ['tone' => 'red', 'title' => '月營收明顯衰退', 'body' => '最新月營收年減 '.abs(round($yoy, 2)).'%，基本面短線承壓。'];
            } elseif ($yoy < 0) {
                $signals[] = ['tone' => 'amber', 'title' => '月營收小幅衰退', 'body' => '最新月營收年增率為 '.round($yoy, 2).'%，需要觀察是否只是短期波動。'];
            }
        }

        if ($financial?->eps !== null) {
            $eps = (float) $financial->eps;

            if ($eps >= 3) {
                $signals[] = ['tone' => 'green', 'title' => 'EPS 表現強', 'body' => '最新每股盈餘為 '.round($eps, 2).' 元，獲利能力具支撐。'];
            } elseif ($eps > 0) {
                $signals[] = ['tone' => 'amber', 'title' => 'EPS 仍為正', 'body' => '最新每股盈餘為 '.round($eps, 2).' 元，獲利存在但強度普通。'];
            } elseif ($eps < 0) {
                $signals[] = ['tone' => 'red', 'title' => 'EPS 轉虧', 'body' => '最新每股盈餘為 '.round($eps, 2).' 元，獲利面出現壓力。'];
            }
        }

        if ($financial?->gross_margin !== null) {
            $margin = (float) $financial->gross_margin;

            if ($margin >= 35) {
                $signals[] = ['tone' => 'green', 'title' => '毛利率偏高', 'body' => '最新毛利率約 '.round($margin, 2).'%，產品或服務具備較佳定價能力。'];
            } elseif ($margin >= 20) {
                $signals[] = ['tone' => 'green', 'title' => '毛利率穩健', 'body' => '最新毛利率約 '.round($margin, 2).'%，獲利結構維持穩定。'];
            } elseif ($margin > 0) {
                $signals[] = ['tone' => 'amber', 'title' => '毛利率偏低', 'body' => '最新毛利率約 '.round($margin, 2).'%，成本或競爭壓力需要觀察。'];
            } elseif ($margin < 0) {
                $signals[] = ['tone' => 'red', 'title' => '毛利率轉負', 'body' => '最新毛利率為負，營運結構明顯承壓。'];
            }
        }

        if ($financial?->roe !== null) {
            $roe = (float) $financial->roe;

            if ($roe >= 15) {
                $signals[] = ['tone' => 'green', 'title' => 'ROE 表現佳', 'body' => '最新 ROE 約 '.round($roe, 2).'%，股東權益報酬率具吸引力。'];
            } elseif ($roe >= 8) {
                $signals[] = ['tone' => 'green', 'title' => 'ROE 穩定', 'body' => '最新 ROE 約 '.round($roe, 2).'%，資本使用效率尚可。'];
            } elseif ($roe >= 0) {
                $signals[] = ['tone' => 'amber', 'title' => 'ROE 偏低', 'body' => '最新 ROE 約 '.round($roe, 2).'%，資本效率仍有改善空間。'];
            } else {
                $signals[] = ['tone' => 'red', 'title' => 'ROE 為負', 'body' => '最新 ROE 為負，代表權益報酬承壓。'];
            }
        }

        if ($financial?->per !== null) {
            $per = (float) $financial->per;

            if ($per > 0 && $per <= 12) {
                $signals[] = ['tone' => 'green', 'title' => '本益比偏低', 'body' => '目前本益比約 '.round($per, 2).' 倍，估值相對保守。'];
            } elseif ($per > 40) {
                $signals[] = ['tone' => 'amber', 'title' => '本益比偏高', 'body' => '目前本益比約 '.round($per, 2).' 倍，市場對成長期待較高，也代表評價風險較高。'];
            } elseif ($per > 25) {
                $signals[] = ['tone' => 'amber', 'title' => '本益比略高', 'body' => '目前本益比約 '.round($per, 2).' 倍，需搭配營收與獲利成長確認合理性。'];
            }
        }

        if ($financial?->pb_ratio !== null) {
            $pb = (float) $financial->pb_ratio;

            if ($pb > 4) {
                $signals[] = ['tone' => 'amber', 'title' => '股價淨值比偏高', 'body' => '目前股價淨值比約 '.round($pb, 2).' 倍，市場給予較高評價。'];
            } elseif ($pb > 0 && $pb <= 1.2) {
                $signals[] = ['tone' => 'green', 'title' => '股價淨值比偏低', 'body' => '目前股價淨值比約 '.round($pb, 2).' 倍，帳面評價相對保守。'];
            }
        }

        if ($financial?->dividend_yield !== null) {
            $yield = (float) $financial->dividend_yield;

            if ($yield >= 5) {
                $signals[] = ['tone' => 'green', 'title' => '殖利率具吸引力', 'body' => '目前殖利率約 '.round($yield, 2).'%，現金回饋具支撐。'];
            } elseif ($yield > 0 && $yield < 1) {
                $signals[] = ['tone' => 'amber', 'title' => '殖利率偏低', 'body' => '目前殖利率約 '.round($yield, 2).'%，股利支撐較弱。'];
            }
        }

        return array_slice($signals, 0, 8);
    }
}
