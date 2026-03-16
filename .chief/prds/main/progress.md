## Codebase Patterns
- Use `php artisan make:model -mf --no-interaction` to scaffold models with migrations and factories
- Use `php artisan make:test --pest {name} --no-interaction` to create Pest feature tests
- Use `vendor/bin/pint --dirty --format agent` before committing PHP changes
- Models use `casts()` method (not `$casts` property) per Laravel 12 convention
- Factories use `fake()` helper (not `$this->faker`)
- The project uses SQLite for local development
- Migration files must be ordered by timestamp — ensure FK dependencies run after parent tables
- Enum cases use TitleCase (e.g., `NotEquals`, `StartsWith`)
- All cascade deletes are handled at the migration level with `->cascadeOnDelete()`
- `WebhookLog` and `AgentRun` use `$timestamps = false` with manual `created_at` only
---

## 2026-03-16 - US-001
- Implemented complete database schema: projects, rules, rule_agent_configs, rule_output_configs, rule_retry_configs, filters, webhook_logs, agent_runs
- Created FilterOperator enum with 7 operators (equals, not_equals, contains, not_contains, starts_with, ends_with, matches)
- All 8 models with relationships, factories, and proper casts
- 19 tests covering all tables, relationships, cascading deletes, factory validity, and operator validation
- Files changed:
  - app/Enums/FilterOperator.php (new)
  - app/Models/{Project,Rule,RuleAgentConfig,RuleOutputConfig,RuleRetryConfig,Filter,WebhookLog,AgentRun}.php (new)
  - database/factories/{ProjectFactory,RuleFactory,RuleAgentConfigFactory,RuleOutputConfigFactory,RuleRetryConfigFactory,FilterFactory,WebhookLogFactory,AgentRunFactory}.php (new)
  - database/migrations/2026_03_16_* (8 migration files)
  - tests/Feature/DatabaseSchemaTest.php (new)
- **Learnings for future iterations:**
  - Migration timestamp collisions can cause FK ordering issues — artisan generates same-second timestamps, rename to ensure correct order
  - Pint auto-fixes `fully_qualified_strict_types` and `ordered_imports` — run it before committing
  - SQLite doesn't strictly enforce FK constraints by default but the schema still works correctly with cascade deletes
---
