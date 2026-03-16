# Dispatch: Local Webhook Server for AI Agent Pipelines

## What it is

A self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules. Each rule maps to a Laravel AI agent with a specific role and toolset. It replaces hardcoded GitHub Actions workflows with a single configurable system a developer runs locally.

## How it works

1. Developer runs the Laravel server locally, exposed via tunnel (ngrok/smee)
2. GitHub repos are configured to send webhooks to this server
3. Server receives a webhook event (e.g. issue labeled, comment created)
4. Matches the event against rules defined in `dispatch.yml` (per-repo config)
5. **All** matching rules fire (evaluated in config order). A rule can set `circuit_break: true` to stop further rule evaluation if it fails.
6. Renders each matched rule's prompt template with data from the webhook payload
7. Dispatches to each rule's configured Laravel AI agent (or falls back to `claude-cli`)
8. Agents with `isolation: worktree` run in a temporary git worktree; others run in the main checkout

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
  instructions_file: "SPARKY.md"    # Read from repo root, used as agent system prompt
  executor: "laravel-ai"            # One of: laravel-ai, claude-cli
  provider: "anthropic"             # AI provider (anthropic, openai, etc.)
  model: "claude-sonnet-4-6"        # Default model for all rules
  secrets:
    api_key: "ANTHROPIC_API_KEY"    # Env var name to read from Dispatch's .env

cache:
  config: true                        # Cache parsed dispatch.yml (clear with artisan dispatch:clear-cache)

rules:
  - id: "analyze"
    name: "Analyze Issue"
    event: "issues.labeled"          # GitHub event type: "{event}.{action}"
    circuit_break: false             # If true and this rule fails, stop processing further rules
    filters:                         # ALL must pass for rule to match
      - id: "filter-1"
        field: "event.label.name"    # Dot-path into webhook payload
        operator: "equals"           # equals|not_equals|contains|not_contains|starts_with|ends_with|matches
        value: "sparky"
    agent:                           # Laravel AI agent configuration for this rule
      provider: null                 # Override repo-level provider
      model: null                    # Override repo-level model
      max_tokens: 4096              # Max tokens for agent response
      tools:                         # Tools available to the agent
        - "read"
        - "glob"
        - "grep"
        - "bash"
      disallowed_tools:              # Tools the agent cannot use
        - "edit"
        - "write"
      isolation: false               # If true, run in a git worktree (safe for concurrent writes)
      structured_output: null        # Optional: response schema class
    output:                          # Where agent output goes
      log: true                      # Always log to webhook_logs table
      github_comment: true           # Post output as a GitHub comment on the source issue/PR
      github_reaction: null          # Add a reaction to the triggering comment (e.g. "eyes", "rocket")
    retry:
      enabled: false                 # Retry on failure
      max_attempts: 3                # Max retry attempts
      delay: 60                      # Seconds between retries
    prompt: |
      You are triaging issue #{{ event.issue.number }}.
      Title: {{ event.issue.title }}
      Body: {{ event.issue.body }}
      ...
```

### Filter logic

Filters within a single rule are AND-combined — all must pass. Available operators:

`equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `matches` (regex)

To express "contains X but not Y" (e.g. the `interactive` rule), use two filters:

```yaml
filters:
  - field: "event.comment.body"
    operator: "contains"
    value: "@sparky"
  - field: "event.comment.body"
    operator: "not_contains"
    value: "@sparky implement"
```

### Template syntax

Prompts use `{{ event.field.path }}` which resolves against the webhook payload. `event.` prefix is stripped, then the remaining dot-path is walked against the payload object.

## Agents

Each rule maps to a Laravel AI agent. The agent's role is defined by:

1. **System prompt** — loaded from `instructions_file` (shared across all rules in a repo)
2. **Rule prompt** — the rendered template specific to that rule, providing the task context
3. **Tools** — configured per-rule to scope what the agent can do (e.g. an "analyze" agent gets read-only tools, an "implement" agent gets write tools)
4. **Provider/model** — configurable per-rule, with repo-level defaults

Agents are Laravel AI SDK agents (`laravel/ai`), supporting:
- Tool use (coding tools mirroring Claude Code; GitHub operations via `gh` CLI through `bash`)
- Structured output for typed responses
- Conversation memory scoped to context (issue, PR, or discussion thread) — agents remember prior interactions within the same thread
- Middleware for logging, rate limiting, etc.

### Tools

Tools are implemented as Laravel AI SDK tool classes, mirroring Claude Code's built-in tools:

| Tool | Implementation | Description |
|---|---|---|
| `read` | PHP file operations | Read file contents |
| `edit` | PHP string replacement | Apply targeted edits to a file |
| `write` | PHP `file_put_contents` | Create or overwrite a file |
| `bash` | `Process::run()` | Run a shell command |
| `glob` | PHP `glob()` / Finder | Find files by pattern |
| `grep` | `Process::run('grep')` | Search file contents |

**GitHub operations** (issues, PRs, discussions, comments, reactions) are handled via `gh` CLI through the `bash` tool. The system prompt instructs agents to use `gh` for all GitHub interactions. This requires `gh` to be installed and authenticated. Git operations (branch creation, commits, push) also go through `bash`.

### Isolation (worktrees)

Rules with `isolation: true` run in a temporary git worktree. This allows multiple agents (e.g. two `implement` runs triggered simultaneously) to operate on the same repo without conflicts. The worktree is created on a unique branch before the agent runs and cleaned up after (or left in place if the agent made commits).

## Executors

Two ways to run agents, configured per-repo in `dispatch.yml`:

| Executor | How it works | Billing | Use case |
|---|---|---|---|
| `laravel-ai` | Laravel AI SDK agent with tools, structured output, memory | API (per-token) | Full agent capabilities — analysis, implementation, review |
| `claude-cli` | Shells out to `claude` with the prompt via `Process::run()`. Respects `agent.tools` / `disallowed_tools` via CLI flags. | Claude Code subscription (flat rate) | Cost-effective alternative to API |

Both executors run with the repo's local checkout (or worktree) as their working directory.

## Webhook endpoint

`POST /api/webhook`

- Reads `X-GitHub-Event` header for event type
- Verifies `X-Hub-Signature-256` against `GITHUB_WEBHOOK_SECRET` env var. Controlled by `VERIFY_WEBHOOK_SIGNATURE` env var (defaults to `true`, set to `false` in dev)
- **Self-loop prevention**: if `payload.sender.login` matches the configured bot user (`GITHUB_BOT_USERNAME` env var), the event is logged but not processed
- Parses payload, constructs full event type as `"{event}.{action}"`
- Looks up local repo path from project map via `payload.repository.full_name`
- Loads and validates `dispatch.yml` from that repo (rejects malformed or invalid configs at load time with a logged error)
- Matches rules (event type must match, ALL filters must pass)
- All matching rules fire. If a rule has `circuit_break: true` and fails, remaining rules are skipped.
- Renders prompt template with payload data for each matched rule
- Queues each agent execution as a job on the `agents` queue (webhook responds immediately)
- Supports `?dry-run=true` query param — returns matched rules + rendered prompts without executing

### Responses

```json
// Success (queued)
{"ok": true, "event": "issues.labeled", "matched": 2, "results": [{"rule": "analyze", "queued": true}, {"rule": "audit", "queued": true}]}

// Dry run
{"ok": true, "event": "issues.labeled", "matched": 1, "dryRun": true, "results": [{"rule": "analyze", "name": "Analyze Issue", "prompt": "rendered prompt..."}]}

// No match
{"ok": true, "event": "issues.labeled", "matched": 0, "results": []}

// Ping
{"ok": true, "event": "ping"}
```

## Webhook event log

Every incoming webhook is logged to a `webhook_logs` table:

- `id`, `event_type`, `repo`, `payload` (JSON), `matched_rules` (JSON), `created_at`
- Each agent execution is logged to an `agent_runs` table:
  - `id`, `webhook_log_id`, `rule_id`, `status` (queued/running/success/failed), `output` (text), `tokens_used`, `cost`, `duration_ms`, `error`, `created_at`

The Livewire UI surfaces these logs for monitoring and debugging.

## Authentication

Requires `gh` CLI to be installed and authenticated. A single GitHub PAT (fine-grained or classic) handles everything:
- GitHub API calls via `gh` CLI (issues, PRs, discussions, comments, reactions)
- Git operations (push, pull) via git credential helpers or `GIT_ASKPASS`
- Set as `GITHUB_TOKEN` env var, used by both `gh` and git

## Default agents (seed from sparky-nano)

The system should ship with 5 default agent roles matching the sparky-nano GitHub Actions workflows:

1. **analyze** (`issues.labeled` where label = "sparky") — Read-only agent that analyzes an issue and produces a plan. Tools: `read`, `glob`, `grep`, `bash`. Isolation: off.
2. **discuss** (`discussion_comment.created` where body contains "@sparky") — Conversational agent for GitHub Discussions. Tools: `bash`. Isolation: off.
3. **implement** (`issue_comment.created` where body contains "@sparky implement") — Full-capability agent that executes the approved plan and creates a PR. Tools: `read`, `edit`, `write`, `bash`, `glob`, `grep`. Isolation: **worktree**.
4. **interactive** (`issue_comment.created` where body contains "@sparky" and not "@sparky implement") — Conversational agent for Q&A on issues. Tools: `read`, `glob`, `grep`, `bash`. Isolation: off.
5. **review** (`pull_request_review_comment.created` where body contains "@sparky") — Code review agent that responds to PR feedback. Tools: `read`, `glob`, `grep`, `bash`. Isolation: off.

## Tech notes

- Built with Laravel 12, PHP 8.5
- Requires `gh` CLI installed and authenticated
- Queue: Redis with Laravel Horizon, agent jobs dispatched to `agents` queue
- The webhook endpoint is a standard Laravel route/controller
- Project map stored in database (projects table: repo, path)
- Config loading reads `dispatch.yml` from the project's local path using `Yaml::parseFile()`, with optional caching
- Config is validated at load time — malformed YAML or missing required fields are rejected with a logged error
- Executors are Laravel services implementing an `Executor` interface
- The `laravel-ai` executor creates Laravel AI agents (`laravel/ai`) with per-rule tool sets, provider/model, and system prompts
- Tools (`read`, `edit`, `write`, `bash`, `glob`, `grep`) are implemented as Laravel AI SDK tool classes, mirroring Claude Code's built-in tools
- GitHub operations use `gh` CLI via the `bash` tool — no separate GitHub SDK needed
- The `claude-cli` executor shells out via `Process::run()` and uses Claude Code's native tools directly
- Conversation memory is scoped per issue/PR/discussion thread — agents have context from prior interactions in the same thread
- Self-loop prevention: events from the bot's own GitHub user are ignored
- Artisan commands for managing projects (`dispatch:add-project`, `dispatch:list-projects`, `dispatch:clear-cache`)
- Livewire/Volt UI for managing projects, viewing webhook logs, and monitoring agent runs
- Tests use Pest 4
- Frontend styled with Tailwind CSS v4 and Flux UI components
