# Architecture

## System Intent

marketx turns global market context into Taiwan stock decision cards.

The system should preserve the causal chain:

```text
Global market signal
-> Global event
-> Industry impact
-> Theme movement
-> Stock-level data
-> Module scores
-> Decision card
-> AI explanation
```

## Engine Boundaries

### Global Engine

Responsibilities:

- Fetch and normalize major global market indicators.
- Track US equities, SOX, VIX, DXY, US yields, oil, gold, and TSMC ADR.
- Produce macro market state labels such as `risk_on`, `risk_off`, `neutral`, and `pressure_down`.

### Event Engine

Responsibilities:

- Ingest global news and official event sources.
- Classify events by topic, region, industry, theme, and affected stocks.
- Store causal links that later feed the AI explanation engine.

### Theme Engine

Responsibilities:

- Maintain theme definitions such as AI Server, Cooling, CoWoS, Optical Communication.
- Compute theme heat from news momentum, related stock performance, volume, chips, and AI event impact.
- Map themes to industries and stocks.

### Taiwan Market Engine

Responsibilities:

- Maintain stock master data.
- Import daily OHLCV.
- Support search by symbol, name, industry, and theme.

### Technical Engine

Responsibilities:

- Calculate SMA, EMA, volume structure, volatility, trend, and breakout signals.
- Convert signals into a 0-100 technical score.

### Chip Engine

Responsibilities:

- Track foreign investor, investment trust, dealer, margin, and short balance data.
- Convert institutional and leverage behavior into a 0-100 chip score.

### Fundamental Engine

Responsibilities:

- Track EPS, ROE, gross margin, PER, and monthly revenue.
- Normalize financial quality and valuation into a 0-100 fundamental score.

### Decision Engine

Responsibilities:

- Combine module scores with explicit weights.
- Produce total score, decision label, confidence, and risk flags.
- Persist explainable score components.

### AI Explain Engine

Responsibilities:

- Read structured data packs, not raw database tables directly.
- Explain why the decision is buy, hold, reduce, or sell.
- Summarize key catalysts and risks.
- Avoid price prediction.

## Page-Level Data Needs

### `/`

- Global market summary.
- Major global events.
- Theme heat ranking.
- High-score stocks.
- Rising-risk stocks.
- Search.

### `/s/{symbol}`

- Header quote block.
- Decision card.
- Module score charts.
- Event impact chain.
- K chart and technical indicators.
- Chip analysis.
- Fundamental analysis.
- AI explanation.
- 30-day score history.

### `/global`

- Global indicators.
- Event timeline.
- AI macro reasoning.

### `/themes`

- Theme heat ranking.
- Theme-to-stock capital flow.
- Theme sentiment history.

### `/admin`

- Job status.
- Crawler status.
- AI generation status.
- Error logs.
- Manual reruns.

