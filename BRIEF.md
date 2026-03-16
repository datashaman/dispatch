# Dispatch: Local Webhook Server for AI Agent Pipelines

## What it is

A self-hosted webhook server that receives GitHub webhook events and dispatches them to Claude (AI) based on configurable rules. It replaces hardcoded GitHub Actions workflows with a single configurable system a developer runs locally.

## How it works

1. Developer runs the server locally, exposed via tunnel (ngrok/smee)
2. GitHub repos are configured to send webhooks to this server
3. Server receives a webhook event (e.g. issue labeled, comment created)
4. Matches the event against rules defined in `dispatch.yml` (per-repo config)
5. Renders the matched rule's prompt template with data from the webhook payload
6. Executes the prompt via one of three configurable executors
7. The executor runs in the local repo checkout directory

## Project map

The server needs a mapping of GitHub repos to local filesystem paths. When a webhook arrives, `repository.full_name` from the payload is used to look up the local checkout. Each repo has its own `dispatch.yml` in its root.

```yaml
# Example project map (stored in database, managed via Artisan commands or UI)
projects:
  - repo: "datashaman/sparky-nano"
    path: "/home/user/Projects/datashaman/sparky-nano"
  - repo: "datashaman/other-project"
    path: "/home/user/Projects/datashaman/other-project"
```

## Config schema (`dispatch.yml`)

Lives in each repo's root directory:

```yaml
version: 1
agent:
  name: "sparky"
  instructions_file: "SPARKY.md"    # Read from repo root, prepended to prompts
  executor: "vercel-ai"              # One of: vercel-ai, claude-cli, claude-agent-sdk
  secrets:
    api_key: "ANTHROPIC_API_KEY"     # Env var name to read

rules:
  - id: "analyze"
    name: "Analyze Issue"
    event: "issues.labeled"          # GitHub event type: "{event}.{action}"
    filters:                         # ALL must pass for rule to match
      - id: "filter-1"
        field: "event.label.name"    # Dot-path into webhook payload
        operator: "equals"           # equals|not_equals|contains|not_contains|starts_with|ends_with|matches
        value: "sparky"
    permissions:                     # Informational (not enforced server-side)
      contents: "read"
      issues: "write"
    action:
      trigger_phrase: ""             # For executor context
      track_progress: true
      show_full_output: true
      branch_prefix: null            # e.g. "sparky/" for implementation rules
      allowed_tools: []              # Tools the agent can use
      disallowed_tools:              # Tools the agent cannot use
        - "Edit"
        - "Write"
    prompt: |
      You are triaging issue #{{ event.issue.number }}.
      Title: {{ event.issue.title }}
      Body: {{ event.issue.body }}
      ...
```

### Template syntax

Prompts use `{{ event.field.path }}` which resolves against the webhook payload. `event.` prefix is stripped, then the remaining dot-path is walked against the payload object.

## Executors

Three ways to run the prompt, configured per-repo in `dispatch.yml`:

| Executor | How it works | Billing | Use case |
|---|---|---|---|
| `vercel-ai` | Vercel AI SDK with `@ai-sdk/anthropic` — simple `generateText()` call | API (per-token) | Simple prompt-to-response (analysis, Q&A) |
| `claude-cli` | Shells out to `claude --print` with the prompt | Claude Code subscription (flat rate) | Cost-effective alternative to API |
| `claude-agent-sdk` | `@anthropic-ai/claude-agent-sdk` — full agentic loop with tools (Read, Edit, Bash, etc.) | API (per-token) | Autonomous implementation (code changes, PRs) |

All executors run with the repo's local checkout as their working directory.

## Webhook endpoint

`POST /api/webhook`

- Reads `X-GitHub-Event` header for event type
- Verifies `X-Hub-Signature-256` against `GITHUB_WEBHOOK_SECRET` env var (optional in dev)
- Parses payload, constructs full event type as `"{event}.{action}"`
- Looks up local repo path from project map via `payload.repository.full_name`
- Loads `dispatch.yml` from that repo
- Matches rules (event type must match, ALL filters must pass)
- First-match wins (rules evaluated in config order)
- Renders prompt template with payload data
- Executes via configured executor in the repo directory
- Supports `?dry-run=true` query param — returns matched rules + rendered prompts without executing

### Responses

```json
// Success
{"ok": true, "event": "issues.labeled", "matched": 1, "results": [{"rule": "analyze", "success": true}]}

// Dry run
{"ok": true, "event": "issues.labeled", "matched": 1, "dryRun": true, "results": [{"rule": "analyze", "name": "Analyze Issue", "prompt": "rendered prompt..."}]}

// No match
{"ok": true, "event": "issues.labeled", "matched": 0, "results": []}

// Ping
{"ok": true, "event": "ping"}
```

## Authentication

A single GitHub PAT (fine-grained or classic) handles everything:
- Git operations (push, pull) via git credential/`gh auth`
- GitHub API calls (create PRs, comment on issues) via `gh` CLI
- Set as env var, used by executors

## Default rules (seed from sparky-nano)

The system should ship with 5 default rules matching the sparky-nano GitHub Actions workflows:

1. **analyze** (`issues.labeled` where label = "sparky") — Read-only analysis of an issue, produces a plan
2. **discuss** (`discussion_comment.created` where body contains "@sparky") — Respond in GitHub Discussions
3. **implement** (`issue_comment.created` where body contains "@sparky implement") — Execute the approved plan, create a PR
4. **interactive** (`issue_comment.created` where body contains "@sparky" but NOT "@sparky implement") — Q&A on issues
5. **review** (`pull_request_review_comment.created` where body contains "@sparky") — Respond to PR review feedback

## Tech notes for Laravel implementation

- The webhook endpoint is a standard Laravel route/controller
- Project map stored in database (projects table: repo, path)
- Config loading reads `dispatch.yml` from the project's local path using `Yaml::parseFile()`
- Executors are Laravel services implementing an `Executor` interface
- The CLI executor shells out via `Process::run()`
- Artisan commands for managing projects (`dispatch:add-project`, `dispatch:list-projects`)
- A simple Blade/Livewire UI for managing projects and viewing webhook logs would be a natural fit later
- Queue long-running executor jobs so the webhook endpoint responds immediately
