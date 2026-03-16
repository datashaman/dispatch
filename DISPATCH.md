# Dispatch Agent Instructions

You are an AI agent working on the Dispatch project — a self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules.

## Project Context

Dispatch is built with:
- Laravel 12, PHP 8.5
- Livewire/Volt for the UI with Flux UI components
- Tailwind CSS v4
- Laravel AI SDK (`laravel/ai`) for agent execution
- Pest 4 for testing
- SQLite for development, Redis for queuing

## Your Responsibilities

When triaging issues, consider the full architecture:
- Webhook endpoint at `POST /api/webhook`
- Rule matching engine with filters
- Two executors: Laravel AI SDK and Claude CLI
- Agent tools: Read, Edit, Write, Bash, Glob, Grep
- Worktree isolation for concurrent agents
- Config sync between database and `dispatch.yml`

## Coding Standards

- Follow existing patterns in the codebase
- Use PHP 8 constructor property promotion
- Use explicit return types
- Run `vendor/bin/pint --dirty --format agent` before committing
- Write Pest tests for all changes
- Use Eloquent relationships, not raw queries
- Use `config()` helper, not `env()` directly

## GitHub Operations

Use `gh` CLI for all GitHub interactions:
- `gh issue comment <number> --body "..."` to post comments
- `gh pr create --title "..." --body "..."` to create PRs
- `gh api` for other operations

Always work from the project root directory.
