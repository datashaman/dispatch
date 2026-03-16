# Dispatch: Local Webhook Server for AI Agent Pipelines

## Overview

A self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules. Rules map to Laravel AI agents with specific roles and toolsets. Replaces hardcoded GitHub Actions workflows with a single configurable system a developer runs locally, exposed via tunnel (ngrok/smee).

## User Stories

### US-001: Database Schema
**Priority:** 1
**Description:** As a developer, I want the database schema in place so that the application has a foundation to build on.

**Acceptance Criteria:**
- [ ] `projects` table: `id`, `repo` (string, unique — GitHub `full_name`), `path` (string — local filesystem path), `timestamps`
- [ ] `rules` table: `id`, `project_id` (FK), `rule_id` (string — user-defined identifier), `name` (string), `event` (string — e.g. `issues.labeled`), `circuit_break` (boolean, default false), `prompt` (text), `sort_order` (integer), `timestamps`
- [ ] `rule_agent_configs` table: `id`, `rule_id` (FK), `provider` (string, nullable), `model` (string, nullable), `max_tokens` (integer, nullable), `tools` (JSON), `disallowed_tools` (JSON), `isolation` (boolean, default false), `timestamps`
- [ ] `rule_output_configs` table: `id`, `rule_id` (FK), `log` (boolean, default true), `github_comment` (boolean, default false), `github_reaction` (string, nullable), `timestamps`
- [ ] `rule_retry_configs` table: `id`, `rule_id` (FK), `enabled` (boolean, default false), `max_attempts` (integer, default 3), `delay` (integer, default 60), `timestamps`
- [ ] `filters` table: `id`, `rule_id` (FK), `filter_id` (string, nullable — user-defined identifier), `field` (string — dot-path into payload), `operator` (string), `value` (string), `sort_order` (integer), `timestamps`
- [ ] `webhook_logs` table: `id`, `event_type` (string), `repo` (string), `payload` (JSON), `matched_rules` (JSON), `status` (string — received/processed/error), `error` (text, nullable), `created_at`
- [ ] `agent_runs` table: `id`, `webhook_log_id` (FK), `rule_id` (string), `status` (string — queued/running/success/failed), `output` (text, nullable), `tokens_used` (integer, nullable), `cost` (decimal, nullable), `duration_ms` (integer, nullable), `error` (text, nullable), `created_at`
- [ ] Models with factories and relationships for all tables
- [ ] Filter `operator` validated against allowed set: `equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `matches`

---

### US-002: Project Management — Artisan Commands
**Priority:** 1
**Description:** As a developer, I want Artisan commands to manage the project map so that I can register local repos with the server.

**Acceptance Criteria:**
- [ ] `dispatch:add-project {repo} {path}` — adds a project to the database, validates path exists on disk
- [ ] `dispatch:remove-project {repo}` — removes a project from the database (with confirmation)
- [ ] `dispatch:list-projects` — lists all registered projects with repo and path
- [ ] Error if repo already exists on add, or doesn't exist on remove
- [ ] Tests for all commands

---

### US-003: Config Loader — Parse & Validate `dispatch.yml`
**Priority:** 1
**Description:** As a developer, I want the system to parse and validate `dispatch.yml` from a project's local path so that rules can be loaded reliably.

**Acceptance Criteria:**
- [ ] `ConfigLoader` service reads `dispatch.yml` from a project's filesystem path using `Yaml::parseFile()`
- [ ] Validates required fields: `version`, `agent.name`, `agent.executor`, `rules` (array)
- [ ] Validates each rule has: `id`, `event`, `prompt`
- [ ] Validates filter operators against allowed set
- [ ] Returns a structured DTO/value object representing the config
- [ ] Throws a descriptive exception on malformed YAML or missing required fields
- [ ] Logs validation errors as warnings
- [ ] Tests for valid configs, missing fields, malformed YAML, missing file

---

### US-004: Config Sync — Bidirectional Sync Between DB and `dispatch.yml`
**Priority:** 1
**Description:** As a developer, I want bidirectional sync between the database and `dispatch.yml` so that I can manage rules in either place.

**Acceptance Criteria:**
- [ ] `dispatch:import {repo}` — reads `dispatch.yml` from the project's local path, upserts rules/filters/agent config/output config/retry config into the database (keyed on `rule_id`)
- [ ] `dispatch:export {repo}` — writes current DB state for a project to `dispatch.yml` in the project's local path
- [ ] Import merges: new rules are added, existing rules are updated, rules in DB but not in file are removed
- [ ] Export produces valid YAML matching the config schema from the brief
- [ ] Repo-level agent config (`agent.name`, `agent.executor`, `agent.provider`, `agent.model`, `agent.secrets`, `agent.instructions_file`) stored on the `projects` table or a related `project_agent_configs` table
- [ ] `dispatch:sync {repo} --direction=import|export` as a unified command (optional convenience alias)
- [ ] Tests for import, export, round-trip (import then export produces equivalent YAML)

---

### US-005: Rule CRUD — Artisan Commands & Models
**Priority:** 2
**Description:** As a developer, I want Artisan commands to manage rules for a project so that I can configure webhook dispatch without editing YAML.

**Acceptance Criteria:**
- [ ] `dispatch:add-rule {repo} {rule_id} {event}` — creates a rule with required fields, accepts options for `--name`, `--prompt`, `--circuit-break`, `--sort-order`
- [ ] `dispatch:update-rule {repo} {rule_id}` — updates rule fields via options
- [ ] `dispatch:remove-rule {repo} {rule_id}` — deletes a rule and its associated filters, agent config, output config, retry config
- [ ] `dispatch:list-rules {repo}` — lists all rules for a project, ordered by `sort_order`
- [ ] Tests for all commands

---

### US-006: Filter CRUD — Manage Filters Per Rule
**Priority:** 2
**Description:** As a developer, I want to manage filters on rules so that I can control which webhook events trigger each rule.

**Acceptance Criteria:**
- [ ] `dispatch:add-filter {repo} {rule_id}` — adds a filter with `--field`, `--operator`, `--value` options
- [ ] `dispatch:remove-filter {repo} {rule_id} {filter_id}` — removes a filter
- [ ] `dispatch:list-filters {repo} {rule_id}` — lists filters for a rule
- [ ] Validates operator against allowed set
- [ ] Tests for all commands

---

### US-007: Agent Config Per Rule
**Priority:** 2
**Description:** As a developer, I want to configure agent settings per rule so that each rule can have its own provider, model, tools, and isolation mode.

**Acceptance Criteria:**
- [ ] `dispatch:configure-agent {repo} {rule_id}` — sets agent config via options: `--provider`, `--model`, `--max-tokens`, `--tools` (comma-separated), `--disallowed-tools` (comma-separated), `--isolation`
- [ ] Falls back to project-level agent config when rule-level values are null
- [ ] `dispatch:show-rule {repo} {rule_id}` — displays full rule config including agent, output, retry, and filters
- [ ] Tests for configuration and fallback behavior

---

### US-008: Output & Retry Config Per Rule
**Priority:** 2
**Description:** As a developer, I want to configure output destinations and retry behavior per rule.

**Acceptance Criteria:**
- [ ] `dispatch:configure-output {repo} {rule_id}` — sets output config via options: `--log`, `--github-comment`, `--github-reaction`
- [ ] `dispatch:configure-retry {repo} {rule_id}` — sets retry config via options: `--enabled`, `--max-attempts`, `--delay`
- [ ] Tests for all commands

---

### US-009: Queue Infrastructure — Horizon & Redis
**Priority:** 3
**Description:** As a developer, I want the queue system configured with Horizon and Redis so that agent jobs are processed reliably.

**Acceptance Criteria:**
- [ ] Laravel Horizon installed and configured
- [ ] `agents` queue defined in Horizon config with appropriate worker settings
- [ ] Redis configured as the queue connection
- [ ] Horizon dashboard accessible at `/horizon`
- [ ] Tests verify jobs dispatch to the `agents` queue

---

### US-010: Webhook Endpoint — Receive, Verify & Parse
**Priority:** 3
**Description:** As a developer, I want a webhook endpoint that receives GitHub events, verifies their authenticity, and parses them so that they can be dispatched to agents.

**Acceptance Criteria:**
- [ ] `POST /api/webhook` route
- [ ] Reads `X-GitHub-Event` header, combines with `payload.action` to form event type (e.g. `issues.labeled`)
- [ ] Verifies `X-Hub-Signature-256` against `GITHUB_WEBHOOK_SECRET` env var when `VERIFY_WEBHOOK_SIGNATURE` is `true` (default)
- [ ] Skips verification when `VERIFY_WEBHOOK_SIGNATURE` is `false`
- [ ] Returns 401 on signature mismatch
- [ ] Handles `ping` event — returns `{"ok": true, "event": "ping"}`
- [ ] Logs every incoming webhook to `webhook_logs` table
- [ ] Returns descriptive JSON error responses (useful for reading in GitHub webhook delivery logs)
- [ ] Tests for valid webhooks, invalid signatures, ping events, missing headers

---

### US-011: Webhook Security — Self-Loop Prevention
**Priority:** 3
**Description:** As a developer, I want the server to ignore events from the bot's own GitHub user so that agents don't trigger infinite loops.

**Acceptance Criteria:**
- [ ] `GITHUB_BOT_USERNAME` env var configures the bot's GitHub login
- [ ] If `payload.sender.login` matches `GITHUB_BOT_USERNAME`, the event is logged with a note but not processed
- [ ] Returns `{"ok": true, "event": "...", "skipped": "self-loop"}` response
- [ ] Tests for self-loop detection

---

### US-012: Rule Matching Engine
**Priority:** 3
**Description:** As a developer, I want incoming webhooks matched against configured rules so that the correct agents are triggered.

**Acceptance Criteria:**
- [ ] Loads rules for the project from the database
- [ ] Matches on event type (exact match against `rule.event`)
- [ ] Evaluates all filters on a matched rule — ALL must pass (AND logic)
- [ ] Filter evaluation supports all operators: `equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `ends_with`, `matches` (regex)
- [ ] Filter `field` is a dot-path resolved against the webhook payload (e.g. `event.label.name`)
- [ ] Returns all matching rules in `sort_order`
- [ ] Handles missing project in project map — returns descriptive JSON error, logs error
- [ ] Handles missing or invalid `dispatch.yml` / DB config — returns descriptive JSON error, logs warning
- [ ] Tests for matching, non-matching, multiple matches, filter operators, error cases

---

### US-013: Prompt Template Rendering
**Priority:** 3
**Description:** As a developer, I want rule prompts rendered with webhook payload data so that agents receive contextual instructions.

**Acceptance Criteria:**
- [ ] `{{ event.field.path }}` syntax resolves against the webhook payload
- [ ] `event.` prefix is stripped, remaining dot-path is walked against the payload object
- [ ] Unresolved paths render as empty string (no errors)
- [ ] Nested paths work (e.g. `{{ event.issue.user.login }}`)
- [ ] Tests for various path depths, missing paths, array access

---

### US-014: Agent Job Dispatching & Circuit Breaker
**Priority:** 3
**Description:** As a developer, I want matched rules dispatched as queued jobs with circuit breaker support so that agents run asynchronously and failures can halt the pipeline.

**Acceptance Criteria:**
- [ ] Each matched rule is dispatched as a job on the `agents` queue
- [ ] Webhook endpoint responds immediately with queued status
- [ ] `agent_runs` record created with status `queued` for each dispatched job
- [ ] Rules are processed in `sort_order`
- [ ] If a rule has `circuit_break: true` and its job fails, remaining rules for that webhook are skipped
- [ ] Circuit-broken rules are logged with status `skipped` and reason
- [ ] Response format matches brief: `{"ok": true, "event": "...", "matched": N, "results": [...]}`
- [ ] Tests for dispatching, circuit breaking, response format

---

### US-015: Executor Interface & Laravel AI Executor
**Priority:** 4
**Description:** As a developer, I want an executor interface and a Laravel AI SDK implementation so that agents can be run via the AI SDK.

**Acceptance Criteria:**
- [ ] `Executor` interface with `execute(AgentRun $run, RenderedPrompt $prompt, AgentConfig $config): ExecutionResult`
- [ ] `LaravelAiExecutor` implementation using `laravel/ai` SDK
- [ ] Creates an agent with the rule's tool set, provider, and model
- [ ] Loads system prompt from `instructions_file` (relative to project path)
- [ ] Uses the rendered rule prompt as the user/task prompt
- [ ] Falls back to project-level provider/model when rule-level is null
- [ ] Updates `agent_runs` record with status, output, tokens_used, cost, duration_ms
- [ ] API key read from Dispatch's `.env` (single key for now)
- [ ] Tests with mocked AI SDK

---

### US-016: Agent Tools — Read, Edit, Write, Bash, Glob, Grep
**Priority:** 4
**Description:** As a developer, I want agent tools implemented as Laravel AI SDK tool classes so that agents can interact with the filesystem and shell.

**Acceptance Criteria:**
- [ ] `ReadTool` — reads file contents from the project's working directory
- [ ] `EditTool` — applies targeted string replacements to a file
- [ ] `WriteTool` — creates or overwrites a file
- [ ] `BashTool` — runs a shell command via `Process::run()`, working directory set to project path (or worktree)
- [ ] `GlobTool` — finds files by pattern using PHP glob/Finder
- [ ] `GrepTool` — searches file contents via `Process::run('grep')`
- [ ] All tools scope operations to the project's working directory (no escaping)
- [ ] Tools are registered per-rule based on `agent_config.tools` and `agent_config.disallowed_tools`
- [ ] Tests for each tool

---

### US-017: Claude CLI Executor
**Priority:** 4
**Description:** As a developer, I want a Claude CLI executor so that agents can run via Claude Code subscription instead of API billing.

**Acceptance Criteria:**
- [ ] `ClaudeCliExecutor` implements `Executor` interface
- [ ] Shells out to `claude` CLI via `Process::run()` with the rendered prompt
- [ ] Sets working directory to project path (or worktree)
- [ ] Passes `agent.tools` as CLI `--allowedTools` flags
- [ ] Passes `agent.disallowed_tools` as CLI `--disallowedTools` flags
- [ ] Captures output and updates `agent_runs` record
- [ ] Tests with mocked Process

---

### US-018: Worktree Isolation
**Priority:** 4
**Description:** As a developer, I want rules with `isolation: true` to run in a temporary git worktree so that concurrent agents don't conflict.

**Acceptance Criteria:**
- [ ] Creates a temporary git worktree on a unique branch before the agent runs
- [ ] Sets the executor's working directory to the worktree path
- [ ] Cleans up the worktree after execution if the agent made no commits
- [ ] Leaves the worktree in place if the agent made commits (for PR creation)
- [ ] Branch naming convention: `dispatch/{rule_id}/{short-hash}` or similar
- [ ] Tests for worktree creation, cleanup, and retention

---

### US-019: Output Handling — GitHub Comments, Reactions & Logging
**Priority:** 5
**Description:** As a developer, I want agent output routed to configured destinations so that results are visible where they're needed.

**Acceptance Criteria:**
- [ ] `log: true` — agent output saved to `agent_runs.output` (always on by default)
- [ ] `github_comment: true` — posts agent output as a comment on the source issue/PR/discussion via `gh` CLI
- [ ] `github_reaction` — adds a reaction (e.g. `eyes`, `rocket`) to the triggering comment via `gh` CLI
- [ ] Determines the correct GitHub resource (issue, PR, discussion) from the webhook payload
- [ ] Tests for each output type

---

### US-020: Conversation Memory — Scoped Per Thread
**Priority:** 5
**Description:** As a developer, I want agents to have conversation memory scoped to the issue/PR/discussion thread so that they have context from prior interactions.

**Acceptance Criteria:**
- [ ] Memory scoped by a key derived from the webhook context (e.g. `repo:issue:123`, `repo:pr:456`)
- [ ] Prior agent outputs and prompts for the same thread are loaded as conversation history
- [ ] Memory passed to the Laravel AI executor as conversation context
- [ ] Claude CLI executor receives memory as part of the prompt (append prior context)
- [ ] Tests for memory scoping and retrieval

---

### US-021: Retry Logic
**Priority:** 5
**Description:** As a developer, I want failed agent runs to be retried based on rule config so that transient failures are handled automatically.

**Acceptance Criteria:**
- [ ] When `retry.enabled` is true, failed jobs are retried up to `retry.max_attempts` times
- [ ] Delay between retries is `retry.delay` seconds
- [ ] Each attempt is logged in `agent_runs` with attempt number
- [ ] Final failure after all retries updates status to `failed`
- [ ] Tests for retry behavior

---

### US-022: Dry-Run Mode
**Priority:** 5
**Description:** As a developer, I want a dry-run mode so that I can test rule matching and prompt rendering without executing agents.

**Acceptance Criteria:**
- [ ] `?dry-run=true` query parameter on `POST /api/webhook`
- [ ] Returns matched rules and rendered prompts without dispatching jobs
- [ ] Response format: `{"ok": true, "event": "...", "matched": N, "dryRun": true, "results": [{"rule": "...", "name": "...", "prompt": "rendered..."}]}`
- [ ] Tests for dry-run responses

---

### US-023: Health Check Command
**Priority:** 5
**Description:** As a developer, I want a health check command so that I can verify the system's dependencies are correctly configured.

**Acceptance Criteria:**
- [ ] `dispatch:health` Artisan command
- [ ] Checks `gh` CLI is installed and authenticated (`gh auth status`)
- [ ] Checks Redis is reachable
- [ ] Checks all registered project paths exist on disk
- [ ] Checks `dispatch.yml` is present and valid for each project
- [ ] Reports pass/fail per check with actionable messages
- [ ] Tests for health check logic

---

### US-024: Project Management UI
**Priority:** 6
**Description:** As a developer, I want a Livewire/Volt UI to manage projects so that I have a visual alternative to Artisan commands.

**Acceptance Criteria:**
- [ ] List all registered projects with repo and path
- [ ] Add a new project (repo + path, validates path exists)
- [ ] Remove a project (with confirmation)
- [ ] Import config from `dispatch.yml` for a project
- [ ] Export config to `dispatch.yml` for a project
- [ ] Built with Livewire/Volt and Flux UI components
- [ ] Styled with Tailwind CSS v4

---

### US-025: Rules & Filters Management UI
**Priority:** 6
**Description:** As a developer, I want a Livewire/Volt UI to manage rules and filters so that I can configure webhook dispatch visually.

**Acceptance Criteria:**
- [ ] List all rules for a project, ordered by sort_order
- [ ] Create, edit, and delete rules
- [ ] Manage filters per rule (add, edit, remove)
- [ ] Configure agent settings per rule (provider, model, tools, isolation)
- [ ] Configure output settings per rule (log, comment, reaction)
- [ ] Configure retry settings per rule
- [ ] Edit prompt template with preview (show template variables)
- [ ] Built with Livewire/Volt and Flux UI components
- [ ] Styled with Tailwind CSS v4

---

### US-026: Webhook Log Viewer & Agent Run Monitoring UI
**Priority:** 6
**Description:** As a developer, I want a UI to view webhook logs and monitor agent runs so that I can debug and observe the system.

**Acceptance Criteria:**
- [ ] List webhook logs with event type, repo, matched rules count, status, timestamp
- [ ] View webhook log detail with full payload and matched rules
- [ ] List agent runs per webhook log with status, duration, tokens, cost
- [ ] View agent run detail with full output and error (if any)
- [ ] Filter logs by repo, event type, status
- [ ] Auto-refresh or polling for in-progress runs
- [ ] Built with Livewire/Volt and Flux UI components
- [ ] Styled with Tailwind CSS v4

---

### US-027: Config Caching
**Priority:** 7
**Description:** As a developer, I want config caching so that repeated webhook processing doesn't re-parse YAML unnecessarily.

**Acceptance Criteria:**
- [ ] When `cache.config: true` in `dispatch.yml`, parsed config is cached
- [ ] `dispatch:clear-cache {repo?}` Artisan command clears cached config (all or specific repo)
- [ ] Cache is invalidated on import/export sync operations
- [ ] Tests for caching and cache clearing

---

### US-028: Default Agent Seed — Sparky-Nano Examples
**Priority:** 7
**Description:** As a developer, I want default agent role templates seeded from the sparky-nano workflows so that I have working examples to start from.

**Acceptance Criteria:**
- [ ] `dispatch:seed-defaults {repo}` command creates 5 default rules for a project:
  - `analyze` — `issues.labeled`, filter: label = "sparky", read-only tools, isolation off
  - `discuss` — `discussion_comment.created`, filter: body contains "@sparky", bash tool only, isolation off
  - `implement` — `issue_comment.created`, filter: body contains "@sparky implement", full tools, isolation on (worktree)
  - `interactive` — `issue_comment.created`, filter: body contains "@sparky" AND not "@sparky implement", read-only + bash, isolation off
  - `review` — `pull_request_review_comment.created`, filter: body contains "@sparky", read-only + bash, isolation off
- [ ] Each rule includes a sensible default prompt template
- [ ] Exports the seeded rules to `dispatch.yml`
- [ ] Tests for seeding
