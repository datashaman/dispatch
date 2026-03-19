# TODOS

## Completed

### P2: Event Sources Beyond GitHub
**What:** Abstract the webhook endpoint to support GitLab, Bitbucket, Slack, Linear, Sentry, PagerDuty, and any webhook-sending service. Each source gets its own signature verification, payload normalization, and event type mapping.
**Why:** Transforms Dispatch from "GitHub automation tool" to "universal event → AI agent router."
**Completed:** v1.0.0 (2026-03-19) — Implemented as built-in adapters via `EventSource`, `OutputAdapter`, and `ThreadKeyDeriver` contracts. GitHub and GitLab sources shipped. `EventSourceRegistry` auto-detects source from request headers. See PR #27.
