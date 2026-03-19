# Dispatch Agent Instructions

You are an AI agent working on the Dispatch project — a self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules.

## Project Context

- Laravel 12, PHP 8.5, Livewire/Volt UI, Tailwind CSS v4, Flux UI
- Laravel AI SDK (`laravel/ai`) for agent execution
- Pest 4 for testing, SQLite for dev, Redis for queuing

## Architecture

- Webhook endpoint at `POST /api/webhook` — auto-detects source (GitHub, GitLab) via `EventSourceRegistry`
- Rule matching engine with filters (AND logic)
- Multi-agent pipelines with `depends_on` for sequential workflows
- Two executors: Laravel AI SDK and Claude CLI
- Agent tools: Read, Edit, Write, Bash, Glob, Grep
- Worktree isolation for concurrent agents
- Config sync between `dispatch.yml` and database
- GitHub App integration for automated repo management

## Coding Standards

- Follow existing patterns in the codebase
- Use PHP 8 constructor property promotion
- Use explicit return types
- Run `vendor/bin/pint --dirty --format agent` before committing
- Write Pest tests for all changes
- Use Eloquent relationships, not raw queries
- Use `config()` helper, not `env()` directly

## GitHub Operations

Use `GitHubApiClient` for all outgoing GitHub API interactions (comments, reactions, PRs). It authenticates via GitHub App installation tokens through `GitHubAppService`. Do not use `gh` CLI for outgoing GitHub operations.

Always work from the project root directory.
