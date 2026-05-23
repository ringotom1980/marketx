# Daily Pipeline

## Daily After-Market Flow

```text
Start daily pipeline
-> Fetch global market data
-> Fetch global news
-> Fetch Taiwan stock data
-> Fetch institutional chip data
-> Fetch financial and revenue data
-> Calculate module scores
-> Calculate total scores
-> Generate decisions
-> Generate AI summaries
-> Write results to database
-> Refresh website cache
-> Record job status and logs
```

## Job Groups

### Market Jobs

- `global:fetch-market-data`
- `twse:fetch-stock-list`
- `twse:fetch-prices`
- `twse:fetch-chip-data`
- `twse:fetch-margin-data`
- `mops:fetch-revenues`
- `mops:fetch-financials`

### Scoring Jobs

- `score:technical`
- `score:chip`
- `score:fundamental`
- `score:theme`
- `score:event`
- `score:decision`

### AI Jobs

- `ai:event-summary`
- `ai:stock-report`
- `ai:watchlist-report`

### Governance Jobs

- `system:health-check`
- `system:cost-check`
- `system:error-notify`

## Failure Policy

- Ingestion jobs should be idempotent.
- Each job writes to `system_jobs`.
- Raw payloads should be retained when possible.
- Failed AI jobs should not block deterministic scores.
- Website pages should show the latest available successful data timestamp.

