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
- Artisan commands use `dispatch:` prefix (e.g., `dispatch:add-project`)
- DTOs live in `app/DataTransferObjects/` as readonly classes with constructor promotion
- Custom exceptions live in `app/Exceptions/`
- Services live in `app/Services/`
- ConfigLoader uses `Symfony\Component\Yaml\Yaml::parseFile()` to parse dispatch.yml
- ConfigSyncer handles bidirectional sync between DB and dispatch.yml via `import()` and `export()` methods
- `expectsOutputToContain` may not match all output — prefer model assertions for data verification, use `expectsTable` for table output
- Project-level agent config stored directly on `projects` table (agent_name, agent_executor, agent_provider, agent_model, agent_instructions_file, agent_secrets)
- Use `Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)` for human-readable YAML export
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

## 2026-03-16 - US-002
- Implemented three Artisan commands: `dispatch:add-project`, `dispatch:remove-project`, `dispatch:list-projects`
- Add validates path exists on disk and repo uniqueness
- Remove includes confirmation prompt
- List displays table of repo/path pairs
- 8 tests covering all commands including error cases and confirmation denial
- Files changed:
  - app/Console/Commands/{AddProjectCommand,RemoveProjectCommand,ListProjectsCommand}.php (new)
  - tests/Feature/ProjectCommandsTest.php (new)
- **Learnings for future iterations:**
  - Artisan command tests use `expectsConfirmation()` for confirm prompts, not `expectsQuestion()`
  - Commands namespace as `dispatch:*` for this project
  - Use `php artisan make:command` to scaffold, then overwrite with implementation
---

## 2026-03-16 - US-003
- Implemented ConfigLoader service to parse and validate `dispatch.yml`
- Created 6 DTOs: DispatchConfig, RuleConfig, FilterConfig, AgentConfig, OutputConfig, RetryConfig
- Created ConfigLoadException for descriptive error reporting
- ConfigLoader validates: required top-level fields (version, agent, rules), agent sub-fields (name, executor), rule required fields (id, event, prompt), filter operators against FilterOperator enum
- Logs validation errors as warnings before throwing exceptions
- 27 tests covering valid configs, all field parsing, missing fields, malformed YAML, invalid operators, minimal rules, multiple rules
- Files changed:
  - app/DataTransferObjects/{DispatchConfig,RuleConfig,FilterConfig,AgentConfig,OutputConfig,RetryConfig}.php (new)
  - app/Exceptions/ConfigLoadException.php (new)
  - app/Services/ConfigLoader.php (new)
  - tests/Feature/ConfigLoaderTest.php (new)
- **Learnings for future iterations:**
  - Symfony Yaml is available as a transitive dependency — no need to install separately
  - Use `sys_get_temp_dir()` with `uniqid()` for temp test directories with YAML fixtures
  - Pint will clean up `@param mixed` PHPDoc tags (no_superfluous_phpdoc_tags) — use type hints directly
  - Log::shouldReceive('warning') works with Mockery for testing logged warnings
---

## 2026-03-16 - US-004
- Implemented bidirectional config sync between DB and dispatch.yml
- Created ConfigSyncer service with `import()`, `export()`, `buildConfigFromDatabase()`, `configToYaml()` methods
- Added migration to store project-level agent config on `projects` table (agent_name, agent_executor, agent_provider, agent_model, agent_instructions_file, agent_secrets)
- Import: loads dispatch.yml via ConfigLoader, upserts rules/filters/configs keyed on rule_id, deletes rules in DB but not in file
- Export: builds DispatchConfig DTO from DB state, converts to YAML, writes to dispatch.yml
- Three Artisan commands: `dispatch:import {repo}`, `dispatch:export {repo}`, `dispatch:sync {repo} --direction=import|export`
- 20 tests covering import, export, round-trip, all sub-configs, merge behavior, command error cases
- Files changed:
  - database/migrations/2026_03_16_062728_add_agent_config_to_projects_table.php (new)
  - app/Models/Project.php (modified — added agent config fillables and casts)
  - app/Services/ConfigSyncer.php (new)
  - app/Console/Commands/{ImportConfigCommand,ExportConfigCommand,SyncConfigCommand}.php (new)
  - tests/Feature/ConfigSyncTest.php (new)
- **Learnings for future iterations:**
  - `updateOrCreate` is ideal for upserting rules by rule_id — avoids manual existence checks
  - Delete-and-recreate is simpler than diffing for child collections like filters
  - `Yaml::dump()` with depth 10 and indent 2 produces readable multi-level YAML
  - Pint fixes `unary_operator_spaces`, `braces_position`, `no_unused_imports` — always run before committing
  - `dispatch:sync` can delegate to other commands via `$this->call()` for convenience aliases
---

## 2026-03-16 - US-005
- Implemented four Artisan commands for Rule CRUD: `dispatch:add-rule`, `dispatch:update-rule`, `dispatch:remove-rule`, `dispatch:list-rules`
- Add accepts required args (repo, rule_id, event) and options (--name, --prompt, --circuit-break, --sort-order)
- Update accepts only the options to change, warns if no options provided
- Remove includes confirmation prompt, cascade deletes handle associated filters/configs
- List displays rules ordered by sort_order in a table
- 17 tests covering all commands including error cases, cascade deletes, confirmation denial, partial updates
- Files changed:
  - app/Console/Commands/{AddRuleCommand,UpdateRuleCommand,RemoveRuleCommand,ListRulesCommand}.php (new)
  - tests/Feature/RuleCommandsTest.php (new)
- **Learnings for future iterations:**
  - Cascade deletes from migrations handle associated config cleanup automatically — no need to manually delete filters/agent_config/output_config/retry_config when deleting a rule
  - `filter_var($value, FILTER_VALIDATE_BOOLEAN)` is useful for parsing string boolean options like --circuit-break=true/false
  - Rule commands follow same pattern as project commands — look up project first, then operate on rules via relationship
---

## 2026-03-16 - US-006
- Implemented three Artisan commands: `dispatch:add-filter`, `dispatch:remove-filter`, `dispatch:list-filters`
- Add validates project/rule existence, requires --field/--operator/--value, validates operator against FilterOperator enum
- Remove includes confirmation prompt, looks up filter by filter_id
- List displays table of filters ordered by sort_order
- 22 tests covering all commands including error cases, operator validation (all 7 operators via dataset), confirmation denial
- Files changed:
  - app/Console/Commands/{AddFilterCommand,RemoveFilterCommand,ListFiltersCommand}.php (new)
  - tests/Feature/FilterCommandsTest.php (new)
- **Learnings for future iterations:**
  - Filter commands follow the same project→rule→filter lookup chain pattern as rule commands
  - Pest's `->with([...])` dataset feature is great for testing all enum values without repetitive tests
  - `FilterOperator::tryFrom()` is cleaner than try/catch for validating enum values from user input
---

## 2026-03-16 - US-007
- Implemented `dispatch:configure-agent` command to set agent config per rule (provider, model, max-tokens, tools, disallowed-tools, isolation)
- Implemented `dispatch:show-rule` command to display full rule configuration including agent, output, retry, and filters
- Show-rule displays project-level fallback values with "(from project)" annotation when rule-level is null
- 13 tests covering configuration, updates, partial updates, fallback behavior, error cases, and full display
- Files changed:
  - app/Console/Commands/ConfigureAgentCommand.php (new)
  - app/Console/Commands/ShowRuleCommand.php (new)
  - tests/Feature/AgentConfigTest.php (new)
- **Learnings for future iterations:**
  - `updateOrCreate` on HasOne relationship works well for agent config — creates if missing, updates if exists
  - `expectsOutputToContain` in Artisan test may not reliably match all output lines — use `expectsOutput` for exact lines or test values directly via model assertions
  - `expectsTable` can be used to verify table output in command tests
  - Comma-separated tool lists are parsed with `array_map('trim', explode(',', $value))` for clean arrays
---

## 2026-03-16 - US-008
- Implemented `dispatch:configure-output` command to set output config per rule (log, github-comment, github-reaction)
- Implemented `dispatch:configure-retry` command to set retry config per rule (enabled, max-attempts, delay)
- Both commands follow the same pattern as `dispatch:configure-agent` — updateOrCreate on HasOne relationship
- 13 tests covering creation, updates, partial updates, disable behavior, no-option warnings, error cases
- Files changed:
  - app/Console/Commands/ConfigureOutputCommand.php (new)
  - app/Console/Commands/ConfigureRetryCommand.php (new)
  - tests/Feature/OutputRetryConfigTest.php (new)
- **Learnings for future iterations:**
  - Output/retry/agent config commands are nearly identical in structure — project→rule lookup, options collection, updateOrCreate pattern
  - Boolean options use `filter_var($value, FILTER_VALIDATE_BOOLEAN)` consistently across all config commands
  - Integer options cast with `(int)` — same pattern in agent config for max_tokens
---

## 2026-03-16 - US-009
- Installed Laravel Horizon (`laravel/horizon ^5.45`) with `composer require` and `horizon:install`
- Configured `agents` queue in Horizon supervisor defaults alongside `default` queue
- Set timeout to 300s for agent jobs (longer-running AI tasks)
- Changed queue connection from `database` to `redis` in `.env` and `.env.example`
- Created `ProcessAgentRun` job dispatching to `agents` queue with `AgentRun` model binding
- HorizonServiceProvider auto-registered in `bootstrap/providers.php`
- Horizon dashboard accessible at `/horizon`
- 7 tests covering: Horizon installation, agents queue config, Redis connection config, dashboard route, job dispatch to agents queue, ShouldQueue implementation, default queue assignment
- Files changed:
  - composer.json, composer.lock (added laravel/horizon)
  - config/horizon.php (new — Horizon config with agents queue)
  - app/Providers/HorizonServiceProvider.php (new — Horizon gate and boot)
  - app/Jobs/ProcessAgentRun.php (new — queued job on agents queue)
  - .env, .env.example (QUEUE_CONNECTION=redis)
  - bootstrap/providers.php (added HorizonServiceProvider)
  - tests/Feature/QueueInfrastructureTest.php (new)
- **Learnings for future iterations:**
  - Horizon dashboard returns 403 in test environment (APP_ENV=testing) due to gate — test for route existence with status in [200, 302, 403]
  - `phpunit.xml` overrides QUEUE_CONNECTION to `sync` for tests — don't test `config('queue.default')` directly, test the config file structure instead
  - Pint auto-fixes `fully_qualified_strict_types` in test files — always run before committing
  - ProcessAgentRun job uses `$this->onQueue('agents')` in constructor for default queue assignment
---
