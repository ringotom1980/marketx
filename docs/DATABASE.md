# Database

This is the first PostgreSQL schema plan. It is intentionally normalized around ingest, scoring, AI reports, and observability.

## Core Tables

### `stocks`

Stock master table.

Suggested columns:

- `id`
- `symbol`
- `name`
- `market`
- `industry`
- `is_active`
- `listed_at`
- `created_at`
- `updated_at`

### `stock_prices_1d`

Daily OHLCV.

Suggested columns:

- `id`
- `stock_id`
- `trade_date`
- `open`
- `high`
- `low`
- `close`
- `change`
- `change_pct`
- `volume`
- `turnover`
- `created_at`
- `updated_at`

### `stock_chips_1d`

Daily institutional and margin data.

Suggested columns:

- `id`
- `stock_id`
- `trade_date`
- `foreign_net_buy`
- `investment_trust_net_buy`
- `dealer_net_buy`
- `margin_balance`
- `short_balance`
- `created_at`
- `updated_at`

### `stock_financials`

Periodic financial metrics.

Suggested columns:

- `id`
- `stock_id`
- `period`
- `eps`
- `roe`
- `gross_margin`
- `operating_margin`
- `per`
- `created_at`
- `updated_at`

### `stock_revenues`

Monthly revenue.

Suggested columns:

- `id`
- `stock_id`
- `year_month`
- `revenue`
- `mom_pct`
- `yoy_pct`
- `created_at`
- `updated_at`

### `global_market_data`

Global indicators.

Suggested columns:

- `id`
- `indicator`
- `trade_date`
- `value`
- `change`
- `change_pct`
- `state`
- `created_at`
- `updated_at`

### `global_events`

Normalized global events.

Suggested columns:

- `id`
- `event_date`
- `source`
- `title`
- `summary`
- `category`
- `region`
- `impact_direction`
- `impact_score`
- `raw_payload`
- `created_at`
- `updated_at`

### `themes`

Theme definitions.

Suggested columns:

- `id`
- `name`
- `slug`
- `description`
- `is_active`
- `created_at`
- `updated_at`

### `theme_scores`

Daily theme heat.

Suggested columns:

- `id`
- `theme_id`
- `score_date`
- `heat_score`
- `news_score`
- `price_score`
- `volume_score`
- `chip_score`
- `ai_event_score`
- `created_at`
- `updated_at`

### `stock_theme_map`

Stock-theme mapping.

Suggested columns:

- `id`
- `stock_id`
- `theme_id`
- `weight`
- `reason`
- `created_at`
- `updated_at`

### `stock_scores`

Daily stock module scores and final decision.

Suggested columns:

- `id`
- `stock_id`
- `score_date`
- `macro_score`
- `event_score`
- `theme_score`
- `technical_score`
- `chip_score`
- `fundamental_score`
- `sentiment_score`
- `total_score`
- `confidence_score`
- `decision`
- `risk_flags`
- `created_at`
- `updated_at`

### `stock_reports`

AI-generated explanation reports.

Suggested columns:

- `id`
- `stock_id`
- `report_date`
- `decision`
- `summary`
- `bull_case`
- `bear_case`
- `risk_summary`
- `data_pack`
- `model`
- `token_usage`
- `created_at`
- `updated_at`

### `watchlist`

User watchlist.

Suggested columns:

- `id`
- `user_id`
- `stock_id`
- `created_at`
- `updated_at`

### `system_jobs`

Job state.

Suggested columns:

- `id`
- `job_name`
- `status`
- `started_at`
- `finished_at`
- `duration_ms`
- `error_message`
- `created_at`
- `updated_at`

### `system_logs`

System logs.

Suggested columns:

- `id`
- `level`
- `source`
- `message`
- `context`
- `created_at`

### `ai_logs`

AI request tracking.

Suggested columns:

- `id`
- `task`
- `model`
- `input_hash`
- `prompt_tokens`
- `completion_tokens`
- `cost_estimate`
- `status`
- `error_message`
- `created_at`

## Indexing Notes

- Add unique constraints on natural daily keys such as `(stock_id, trade_date)`, `(theme_id, score_date)`, and `(stock_id, score_date)`.
- Index `stocks.symbol`, `stocks.name`, and `themes.slug`.
- Use JSONB for `raw_payload`, `risk_flags`, `data_pack`, and log `context`.

