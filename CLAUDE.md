# Dispatch — Project Instructions

Dispatch is a self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules.

## Architecture

- Webhook endpoint at `POST /api/webhook` — auto-detects source (GitHub, GitLab), verifies signatures, matches rules, dispatches agents
- Multi-source event system with pluggable `EventSource`, `OutputAdapter`, and `ThreadKeyDeriver` contracts
- Rule matching engine with field filters (equals, contains, regex, etc.)
- Multi-agent pipelines with `depends_on` for sequential workflows
- Two executors: Laravel AI SDK (`laravel-ai`) and Claude CLI (`claude-cli`)
- Agent tools: Read, Edit, Write, Bash, Glob, Grep
- Worktree isolation for concurrent agent execution
- Config sync between `dispatch.yml` files and database
- GitHub App integration for automated repo management
- Prompt injection defense for webhook payloads
- Live agent streaming via Laravel Reverb

## Tech Stack

- Laravel 12, PHP 8.4
- Livewire 4 / Volt (single-file components) for the UI
- Flux UI (free tier) component library
- Tailwind CSS v4
- Laravel AI SDK (`laravel/ai`) for agent execution
- Pest 4 for testing
- SQLite (dev), Redis (queuing)
- GitHub API via `GitHubApiClient` (HTTP with installation tokens) for outgoing GitHub operations

## Key Services

| Service | Purpose |
|---------|--------|
| `EventSourceRegistry` | Auto-detects webhook source, resolves EventSource/OutputAdapter/ThreadKeyDeriver |
| `RuleMatchingEngine` | Matches webhook events to rules using filters |
| `AgentDispatcher` | Queues agent jobs |
| `PipelineOrchestrator` | Orchestrates multi-agent pipelines with `depends_on` |
| `ConversationMemory` | Thread-based conversation memory for agent continuity |
| `ConfigSyncer` | Bidirectional sync between `dispatch.yml` and database |
| `GitHubAppService` | JWT auth, installation tokens, repo listing, manifest flow |
| `OutputHandler` | Routes agent output via source-specific OutputAdapters (with legacy fallback) |
| `GitHubApiClient` | HTTP client for GitHub API using installation tokens |
| `PromptRenderer` | Template rendering with dot notation for event variables |
| `WorktreeManager` | Creates isolated git worktrees for agent execution |
| `ToolRegistry` | Resolves available tools for agents |

## Coding Standards

- Follow existing patterns in the codebase
- Use PHP 8 constructor property promotion
- Use explicit return types
- Run `vendor/bin/pint --dirty --format agent` before committing
- Write Pest tests for all changes
- Use Eloquent relationships, not raw queries
- Use `config()` helper, not `env()` directly

## GitHub Operations

Use `GitHubApiClient` for all GitHub API interactions (comments, reactions). It authenticates via GitHub App installation tokens through `GitHubAppService`. Do not use `gh` CLI for outgoing GitHub operations.

## dispatch.yml

Projects can define rules in a `dispatch.yml` file at the repo root. The config syncer imports/exports between this file and the database. See `dispatch.yml` in this repo for a working example with rules for issue triage, implementation, interactive Q&A, and code review response.

## Design System
Always read DESIGN.md before making any visual or UI decisions.
All font choices, colors, spacing, and aesthetic direction are defined there.
Do not deviate without explicit user approval.
In QA mode, flag any code that doesn't match DESIGN.md.

## gstack

- Use the `/browse` skill from gstack for all web browsing. Never use `mcp__claude-in-chrome__*` tools.
- Available skills: `/office-hours`, `/plan-ceo-review`, `/plan-eng-review`, `/plan-design-review`, `/design-consultation`, `/review`, `/ship`, `/browse`, `/qa`, `/qa-only`, `/design-review`, `/setup-browser-cookies`, `/retro`, `/debug`, `/document-release`
- If gstack skills aren't working, run `cd .claude/skills/gstack && ./setup` to build the binary and register skills.
