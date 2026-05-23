# marketx

《股市在幹嘛》是一套全球事件驅動的台股決策雷達系統。

它不是報明牌、預測股價、當沖或自動交易系統，而是把全球市場、事件、題材熱度、台股籌碼、技術面、財務與市場情緒整合成個股決策卡，協助判斷「買進 / 續抱 / 減碼 / 賣出」方向。

## Product Positioning

核心價值鏈：

```text
全球市場
-> 全球事件
-> 產業影響
-> 題材熱度
-> 個股狀態
-> 多因子評分
-> 買進 / 續抱 / 減碼 / 賣出
```

最終產品定位：

```text
全球事件驅動的台股決策作業系統
```

## Core Engines

| Engine | Purpose |
| --- | --- |
| Global Engine | 全球市場資料 |
| Event Engine | 全球事件分析與分類 |
| Theme Engine | 題材熱度與資金流向 |
| Taiwan Market Engine | 台股價格與基本交易資料 |
| Technical Engine | 技術面指標與結構分析 |
| Chip Engine | 法人、融資融券與籌碼分析 |
| Fundamental Engine | 財務、營收與估值分析 |
| Decision Engine | 多因子總分、決策與信心度 |
| AI Explain Engine | 事件推理、人話摘要與風險說明 |

## Main Pages

| Route | Page |
| --- | --- |
| `/` | 今日全球 x 台股狀態中心 |
| `/s/{symbol}` | 個股決策卡 |
| `/global` | 全球雷達 |
| `/themes` | 題材雷達 |
| `/watchlist` | 追蹤清單 |
| `/admin` | 系統後台 |

## Decision Scale

| Score | Decision |
| --- | --- |
| 85-100 | 強力買進 |
| 70-84 | 買進 |
| 55-69 | 續抱 |
| 40-54 | 減碼 |
| 0-39 | 賣出 |

## Suggested Stack

| Layer | Choice |
| --- | --- |
| Web | Laravel, Blade, JavaScript, CSS |
| Jobs | Python batch jobs |
| Database | PostgreSQL |
| Charts | TradingView Lightweight Charts |
| AI | OpenAI API |
| Deployment | VPS, Nginx, SSL, GitHub Deploy |

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Database](docs/DATABASE.md)
- [Scoring](docs/SCORING.md)
- [Roadmap](docs/ROADMAP.md)
- [Daily Pipeline](docs/DAILY_PIPELINE.md)

