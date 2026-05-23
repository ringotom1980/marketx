# Scoring

All module scores use a 0-100 scale. The first production version should favor explainability over complexity.

## Module Scores

| Module | Purpose |
| --- | --- |
| Macro Score | Global market pressure or support |
| Event Score | Direct and indirect impact from major events |
| Theme Score | Theme heat and relevance to the stock |
| Technical Score | Trend, price-volume structure, breakout, volatility |
| Chip Score | Foreign, investment trust, dealer, and margin behavior |
| Fundamental Score | EPS, ROE, margin, revenue, valuation |
| Sentiment Score | Market tone, news tone, and crowding risk |

## Initial Weights

| Module | Weight |
| --- | ---: |
| Macro Score | 10% |
| Event Score | 15% |
| Theme Score | 20% |
| Technical Score | 20% |
| Chip Score | 15% |
| Fundamental Score | 15% |
| Sentiment Score | 5% |

Formula:

```text
total_score =
  macro_score * 0.10 +
  event_score * 0.15 +
  theme_score * 0.20 +
  technical_score * 0.20 +
  chip_score * 0.15 +
  fundamental_score * 0.15 +
  sentiment_score * 0.05
```

## Decision Labels

| Score | Decision |
| --- | --- |
| 85-100 | 強力買進 |
| 70-84 | 買進 |
| 55-69 | 續抱 |
| 40-54 | 減碼 |
| 0-39 | 賣出 |

## Confidence Score

Confidence should not simply equal total score. It should reflect data quality and agreement across modules.

Initial inputs:

- Data freshness.
- Number of available modules.
- Agreement between technical, chip, theme, and fundamental signals.
- Event certainty.
- Volatility penalty.

Example:

```text
confidence =
  data_completeness_score * 0.35 +
  module_agreement_score * 0.35 +
  event_certainty_score * 0.20 -
  volatility_penalty * 0.10
```

## Risk Flags

Examples:

- `theme_cooling`
- `high_volume_top`
- `institutional_selling`
- `margin_balance_rising`
- `valuation_expensive`
- `event_uncertainty`
- `macro_pressure`

## AI Rules

AI should:

- Explain causal links.
- Summarize bullish and bearish evidence.
- Highlight the largest current risk.
- Translate scores into readable reasoning.

AI should not:

- Predict exact stock prices.
- Promise returns.
- Create unsupported buy or sell claims outside the data pack.
- Replace the deterministic scoring engine.

