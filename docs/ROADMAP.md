# Roadmap

## Phase 1: Foundation

- Initialize Git repo.
- Create Laravel project.
- Configure PostgreSQL.
- Prepare VPS.
- Configure Nginx.
- Configure SSL.
- Configure GitHub deploy.

## Phase 2: Stock Data System

- Create `stocks`.
- Import stock list.
- Create daily OHLCV table.
- Import daily OHLCV.
- Build stock search.
- Build stock page shell.

## Phase 3: Technical Analysis

- Calculate moving averages.
- Calculate trend state.
- Calculate volatility.
- Calculate price-volume structure.
- Calculate Technical Score.

## Phase 4: Chip System

- Import institutional data.
- Import margin and short data.
- Calculate Chip Score.

## Phase 5: Fundamental System

- Import monthly revenue.
- Import EPS, ROE, gross margin, PER.
- Calculate Fundamental Score.

## Phase 6: Decision Engine

- Implement module score aggregation.
- Produce decision label.
- Produce confidence score.
- Store daily score history.

## Phase 7: Global Market

- Import US equities.
- Import SOX.
- Import VIX.
- Import US yields.
- Import DXY.
- Import oil and gold.

## Phase 8: Global Event Engine

- Fetch global news.
- Generate AI summaries.
- Classify events.
- Classify industries.
- Map events to stocks.

## Phase 9: Theme Radar

- Create theme tables.
- Calculate heat score.
- Build theme page.
- Maintain theme-stock mapping.

## Phase 10: AI Explain Engine

- Build stock data pack.
- Build AI prompt.
- Generate AI report.
- Generate risk summary.

## Phase 11: Watchlist

- Build watchlist.
- Run daily watchlist updates.
- Generate daily AI reports.

## Phase 12: Governance

- Add scheduler.
- Add logs.
- Add error notifications.
- Add AI cost control.
- Add caching.

## First Build Slice

Recommended first working milestone:

1. Laravel app boots.
2. PostgreSQL connected.
3. `stocks` and `stock_prices_1d` migrations exist.
4. Seed a small stock list.
5. `/` shows mock market state.
6. `/s/{symbol}` shows a real stock header from DB.
7. Decision card uses deterministic mock module scores.

This keeps the first iteration visible and testable before adding crawlers and AI cost.

