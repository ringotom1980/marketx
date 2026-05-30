# MarketX AI Agent Learning Plan

## Goal

Build a VPS-based multi-agent learning system for MarketX.

Agents do not directly change production behavior. They collect, classify, suggest, validate, and prepare cases. Codex reviews and publishes approved changes.

## Five Stages

### Stage 1: Knowledge Base

Tables:

- `market_knowledge_items`: news, events, reports, theme knowledge, industry knowledge, historical cases.
- Sources initially come from existing MarketX data: `global_events`, `global_event_clusters`, `global_ai_reports`, and `theme_ai_reports`.
- Future news crawlers can write into the same table without changing later stages.

Command:

```bash
php artisan market:agents-learning-pipeline --phase=collect
```

Schedule:

```text
Every 6 hours at minute 10
```

### Stage 2: Classification

Classifies active knowledge into:

- keywords
- themes
- industries
- sentiment
- expired knowledge

Command:

```bash
php artisan market:agents-learning-pipeline --phase=classify
```

Schedule:

```text
Daily 01:12 Asia/Taipei
```

### Stage 3: Language Assets

Tables:

- `language_assets`: phrases, connectors, tone sentences, report language fragments.
- `paragraph_templates`: reusable paragraph structures.
- `article_templates`: full report structures selected by scenario.
- `agent_learning_suggestions`: pending suggestions for Codex review.

Command:

```bash
php artisan market:agents-learning-pipeline --phase=language
```

Schedule:

```text
Daily 01:52 Asia/Taipei
```

### Stage 4: Rule Governance

Checks whether current rules look contradictory, especially:

- risk cards vs high confidence
- priority/potential cards with missing theme support
- stock report language that conflicts with card type
- radar classification rules that need tuning

Command:

```bash
php artisan market:agents-learning-pipeline --phase=rules
```

Schedule:

```text
Daily 02:08 Asia/Taipei
```

### Stage 5: Review Queue

Groups learning suggestions into Codex-reviewable cases.

Output:

- `agent_findings` cases with `finding_type = learning_suggestion_review`
- `agent_learning_suggestions` records tagged as pending or approved
- exported agent knowledge pack for Ollama and future local agents

Command:

```bash
php artisan market:agents-learning-pipeline --phase=review
php artisan market:export-agent-knowledge-pack
```

Schedule:

```text
Daily 02:18 Asia/Taipei
Daily 00:55 export knowledge pack
```

## Agent Roles

- `news-collector`: collects source knowledge.
- `knowledge-classifier`: classifies and cleans knowledge.
- `language-curator`: suggests phrases, fragments, paragraph templates, article templates.
- `rule-governor`: checks scoring and card logic.
- `ollama-orchestrator`: consolidates suggestions into Codex review cases.
- `validation-agent`: validates radar card performance over time.

## Current Status

The initial implementation is deployed on the VPS and has completed one full run.

Latest verified counts:

- Knowledge items: 162
- Published language assets: 12
- Published paragraph templates: 1
- Published article templates: 1
- Learning suggestions: 25 approved, 1 pending

## Codex Review Rule

Agents may suggest changes, but formal behavior changes must go through Codex review.

Safe language assets and templates can be published by Codex using:

```bash
php artisan market:agents-learning-pipeline --phase=review --publish-safe-defaults
```

Rule changes should remain pending until reviewed and implemented in code.
