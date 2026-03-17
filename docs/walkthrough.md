# Dispatch — Code Walkthrough

*2026-03-17T13:15:35Z by Showboat 0.6.1*
<!-- showboat-id: 2c2fc69b-6e2c-46b6-8f78-f827f977af07 -->

Dispatch is a self-hosted webhook server that receives GitHub webhook events and dispatches them to AI agents based on configurable rules. This walkthrough traces the complete lifecycle of a webhook event — from ingestion through rule matching, agent execution, and output routing back to GitHub.

## Overview

The architecture follows a pipeline pattern:

1. **Webhook arrives** → validate signature, log, extract event type
2. **Match rules** → filter by event type, evaluate field filters
3. **Dispatch jobs** → create AgentRun records, queue processing jobs
4. **Execute agent** → load conversation history, render prompt, call executor
5. **Route output** → post GitHub comment, add reaction
6. **Cleanup** → remove or retain git worktrees

## Step 1: Webhook Ingestion

Everything starts at the API route. A single POST endpoint receives all GitHub webhooks:

```bash
cat -n routes/api.php
```

```output
     1	<?php
     2	
     3	use App\Http\Controllers\GitHubAppController;
     4	use App\Http\Controllers\WebhookController;
     5	use Illuminate\Support\Facades\Route;
     6	
     7	Route::post('/webhook', [WebhookController::class, 'handle']);
     8	Route::post('/github/webhook', [GitHubAppController::class, 'webhook']);
```

The `WebhookController::handle()` method is the heart of ingestion. It validates the GitHub signature, detects self-loops (bot reacting to its own comments), logs the event, and kicks off rule matching. Let's look at the controller:

```bash
cat -n app/Http/Controllers/WebhookController.php
```

```output
     1	<?php
     2	
     3	namespace App\Http\Controllers;
     4	
     5	use App\Exceptions\RuleMatchingException;
     6	use App\Models\Project;
     7	use App\Models\WebhookLog;
     8	use App\Services\AgentDispatcher;
     9	use App\Services\ConfigLoader;
    10	use App\Services\PromptRenderer;
    11	use App\Services\RuleMatchingEngine;
    12	use Illuminate\Http\JsonResponse;
    13	use Illuminate\Http\Request;
    14	
    15	class WebhookController extends Controller
    16	{
    17	    public function __construct(
    18	        protected RuleMatchingEngine $engine,
    19	        protected AgentDispatcher $dispatcher,
    20	        protected PromptRenderer $promptRenderer,
    21	        protected ConfigLoader $configLoader,
    22	    ) {}
    23	
    24	    public function handle(Request $request): JsonResponse
    25	    {
    26	        $githubEvent = $request->header('X-GitHub-Event');
    27	
    28	        if (! $githubEvent) {
    29	            return response()->json([
    30	                'ok' => false,
    31	                'error' => 'Missing X-GitHub-Event header',
    32	            ], 400);
    33	        }
    34	
    35	        if ($this->shouldVerifySignature()) {
    36	            $signature = $request->header('X-Hub-Signature-256');
    37	            $secret = config('services.github.webhook_secret');
    38	
    39	            if (! $signature || ! $secret) {
    40	                $this->logWebhook($githubEvent, $request, 'error', 'Missing signature or webhook secret');
    41	
    42	                return response()->json([
    43	                    'ok' => false,
    44	                    'error' => 'Missing X-Hub-Signature-256 header or webhook secret not configured',
    45	                ], 401);
    46	            }
    47	
    48	            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
    49	
    50	            if (! hash_equals($expectedSignature, $signature)) {
    51	                $this->logWebhook($githubEvent, $request, 'error', 'Invalid signature');
    52	
    53	                return response()->json([
    54	                    'ok' => false,
    55	                    'error' => 'Invalid webhook signature',
    56	                ], 401);
    57	            }
    58	        }
    59	
    60	        if ($this->isSelfLoop($request)) {
    61	            $action = $request->input('action');
    62	            $eventType = $action ? "{$githubEvent}.{$action}" : $githubEvent;
    63	
    64	            $this->logWebhook($eventType, $request, 'received', 'Self-loop detected');
    65	
    66	            return response()->json([
    67	                'ok' => true,
    68	                'event' => $eventType,
    69	                'skipped' => 'self-loop',
    70	            ]);
    71	        }
    72	
    73	        if ($githubEvent === 'ping') {
    74	            $this->logWebhook('ping', $request, 'received');
    75	
    76	            return response()->json([
    77	                'ok' => true,
    78	                'event' => 'ping',
    79	            ]);
    80	        }
    81	
    82	        $action = $request->input('action');
    83	        $eventType = $action ? "{$githubEvent}.{$action}" : $githubEvent;
    84	
    85	        $webhookLog = $this->logWebhook($eventType, $request, 'received');
    86	
    87	        $repo = $request->input('repository.full_name');
    88	
    89	        if (! $repo) {
    90	            $webhookLog->update(['status' => 'error', 'error' => 'Missing repository.full_name in payload']);
    91	
    92	            return response()->json([
    93	                'ok' => false,
    94	                'error' => 'Missing repository.full_name in payload',
    95	                'webhook_log_id' => $webhookLog->id,
    96	            ], 422);
    97	        }
    98	
    99	        try {
   100	            $matchedRules = $this->engine->match($repo, $eventType, $request->all());
   101	        } catch (RuleMatchingException $e) {
   102	            $webhookLog->update(['status' => 'error', 'error' => $e->getMessage()]);
   103	
   104	            return response()->json([
   105	                'ok' => false,
   106	                'error' => $e->getMessage(),
   107	                'webhook_log_id' => $webhookLog->id,
   108	            ], 422);
   109	        }
   110	
   111	        $webhookLog->update([
   112	            'matched_rules' => $matchedRules->pluck('id')->toArray(),
   113	            'status' => 'processed',
   114	        ]);
   115	
   116	        if ($request->boolean('dry-run')) {
   117	            $results = $matchedRules->map(fn ($rule) => [
   118	                'rule' => $rule->id,
   119	                'name' => $rule->name,
   120	                'prompt' => $this->promptRenderer->render($rule->prompt, $request->all()),
   121	            ])->values()->toArray();
   122	
   123	            return response()->json([
   124	                'ok' => true,
   125	                'event' => $eventType,
   126	                'matched' => $matchedRules->count(),
   127	                'dryRun' => true,
   128	                'webhook_log_id' => $webhookLog->id,
   129	                'results' => $results,
   130	            ]);
   131	        }
   132	
   133	        // Load project and config for the dispatcher
   134	        $project = Project::where('repo', $repo)->firstOrFail();
   135	        $config = $this->configLoader->load($project->path);
   136	
   137	        $results = $this->dispatcher->dispatch($webhookLog, $matchedRules, $request->all(), $project, $config);
   138	
   139	        return response()->json([
   140	            'ok' => true,
   141	            'event' => $eventType,
   142	            'matched' => $matchedRules->count(),
   143	            'webhook_log_id' => $webhookLog->id,
   144	            'results' => $results,
   145	        ]);
   146	    }
   147	
   148	    protected function isSelfLoop(Request $request): bool
   149	    {
   150	        $botUsername = config('services.github.bot_username');
   151	
   152	        if (! $botUsername) {
   153	            return false;
   154	        }
   155	
   156	        $senderLogin = $request->input('sender.login');
   157	
   158	        return $senderLogin === $botUsername;
   159	    }
   160	
   161	    protected function shouldVerifySignature(): bool
   162	    {
   163	        return config('services.github.verify_webhook_signature', true);
   164	    }
   165	
   166	    protected function logWebhook(string $eventType, Request $request, string $status, ?string $error = null): WebhookLog
   167	    {
   168	        $payload = $request->all();
   169	        $repo = $payload['repository']['full_name'] ?? null;
   170	
   171	        return WebhookLog::create([
   172	            'event_type' => $eventType,
   173	            'repo' => $repo,
   174	            'payload' => $payload,
   175	            'status' => $status,
   176	            'error' => $error,
   177	            'created_at' => now(),
   178	        ]);
   179	    }
   180	}
```

Key things happening here:

- **Signature verification** (line 35-58): HMAC-SHA256 validation using the shared webhook secret. This ensures the request actually came from GitHub.
- **Self-loop detection** (line 60-71): Checks if `sender.login` matches the configured bot username. Without this, the bot would trigger itself infinitely — post a comment → webhook fires → bot comments again → etc.
- **Event type construction** (line 82-83): GitHub sends the event category in the header (`issues`, `issue_comment`) and the action in the payload (`opened`, `created`). These are combined into `issues.opened` or `issue_comment.created`.
- **Dry-run mode** (line 116-131): Appending `?dry-run=true` returns matched rules with rendered prompts but doesn't execute anything. Useful for testing rule configurations.
- **Dispatch** (line 134-137): Loads the project, reads its `dispatch.yml` config, and hands off to the `AgentDispatcher`.

## Step 2: Configuration Loading

Before rules can be matched, the system needs to load the project's `dispatch.yml`. The `ConfigLoader` handles parsing, validation, and optional caching:

```bash
cat -n app/Services/ConfigLoader.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\DataTransferObjects\AgentConfig;
     6	use App\DataTransferObjects\DispatchConfig;
     7	use App\DataTransferObjects\FilterConfig;
     8	use App\DataTransferObjects\OutputConfig;
     9	use App\DataTransferObjects\RetryConfig;
    10	use App\DataTransferObjects\RuleConfig;
    11	use App\Enums\FilterOperator;
    12	use App\Exceptions\ConfigLoadException;
    13	use Illuminate\Support\Facades\Cache;
    14	use Illuminate\Support\Facades\Log;
    15	use Symfony\Component\Yaml\Exception\ParseException;
    16	use Symfony\Component\Yaml\Yaml;
    17	
    18	class ConfigLoader
    19	{
    20	    /**
    21	     * Load and validate a dispatch.yml from the given project path.
    22	     * If the config has caching enabled, the result is cached.
    23	     */
    24	    public function load(string $projectPath): DispatchConfig
    25	    {
    26	        $cacheKey = self::cacheKey($projectPath);
    27	
    28	        $cached = Cache::get($cacheKey);
    29	        if ($cached instanceof DispatchConfig) {
    30	            return $cached;
    31	        }
    32	
    33	        $config = $this->loadFromDisk($projectPath);
    34	
    35	        if ($config->cacheConfig) {
    36	            Cache::put($cacheKey, $config);
    37	        }
    38	
    39	        return $config;
    40	    }
    41	
    42	    /**
    43	     * Load config from disk without checking cache.
    44	     */
    45	    public function loadFromDisk(string $projectPath): DispatchConfig
    46	    {
    47	        $filePath = rtrim($projectPath, '/').'/dispatch.yml';
    48	
    49	        if (! file_exists($filePath)) {
    50	            throw new ConfigLoadException("Config file not found: {$filePath}");
    51	        }
    52	
    53	        try {
    54	            $data = Yaml::parseFile($filePath);
    55	        } catch (ParseException $e) {
    56	            throw new ConfigLoadException("Malformed YAML in {$filePath}: {$e->getMessage()}");
    57	        }
    58	
    59	        if (! is_array($data)) {
    60	            throw new ConfigLoadException("Config file must contain a YAML mapping: {$filePath}");
    61	        }
    62	
    63	        return $this->validate($data, $filePath);
    64	    }
    65	
    66	    /**
    67	     * Clear cached config for a project path.
    68	     */
    69	    public function clearCache(string $projectPath): void
    70	    {
    71	        Cache::forget(self::cacheKey($projectPath));
    72	    }
    73	
    74	    /**
    75	     * Generate the cache key for a project path.
    76	     */
    77	    public static function cacheKey(string $projectPath): string
    78	    {
    79	        return 'dispatch:config:'.md5(rtrim($projectPath, '/'));
    80	    }
    81	
    82	    /**
    83	     * Validate parsed YAML data and return a DispatchConfig DTO.
    84	     *
    85	     * @param  array<string, mixed>  $data
    86	     */
    87	    private function validate(array $data, string $filePath): DispatchConfig
    88	    {
    89	        $this->requireField($data, 'version', $filePath);
    90	        $this->requireField($data, 'agent', $filePath);
    91	
    92	        $agent = $data['agent'];
    93	
    94	        if (! is_array($agent)) {
    95	            throw new ConfigLoadException("Field 'agent' must be a mapping in {$filePath}");
    96	        }
    97	
    98	        $this->requireField($agent, 'name', $filePath, 'agent.');
    99	        $this->requireField($agent, 'executor', $filePath, 'agent.');
   100	
   101	        $this->requireField($data, 'rules', $filePath);
   102	
   103	        if (! is_array($data['rules'])) {
   104	            throw new ConfigLoadException("Field 'rules' must be an array in {$filePath}");
   105	        }
   106	
   107	        $rules = [];
   108	        foreach ($data['rules'] as $index => $ruleData) {
   109	            $rules[] = $this->validateRule($ruleData, $index, $filePath);
   110	        }
   111	
   112	        $cache = $data['cache'] ?? [];
   113	
   114	        return new DispatchConfig(
   115	            version: (int) $data['version'],
   116	            agentName: $agent['name'],
   117	            agentExecutor: $agent['executor'],
   118	            agentInstructionsFile: $agent['instructions_file'] ?? null,
   119	            agentProvider: $agent['provider'] ?? null,
   120	            agentModel: $agent['model'] ?? null,
   121	            secrets: $agent['secrets'] ?? null,
   122	            cacheConfig: (bool) ($cache['config'] ?? false),
   123	            rules: $rules,
   124	        );
   125	    }
   126	
   127	    /**
   128	     * Validate a single rule entry.
   129	     */
   130	    private function validateRule(mixed $ruleData, int $index, string $filePath): RuleConfig
   131	    {
   132	        if (! is_array($ruleData)) {
   133	            throw new ConfigLoadException("Rule at index {$index} must be a mapping in {$filePath}");
   134	        }
   135	
   136	        $prefix = "rules[{$index}].";
   137	        $this->requireField($ruleData, 'id', $filePath, $prefix);
   138	        $this->requireField($ruleData, 'event', $filePath, $prefix);
   139	        $this->requireField($ruleData, 'prompt', $filePath, $prefix);
   140	
   141	        $filters = [];
   142	        if (isset($ruleData['filters'])) {
   143	            foreach ($ruleData['filters'] as $filterIndex => $filterData) {
   144	                $filters[] = $this->validateFilter($filterData, $index, $filterIndex, $filePath);
   145	            }
   146	        }
   147	
   148	        return new RuleConfig(
   149	            id: $ruleData['id'],
   150	            event: $ruleData['event'],
   151	            prompt: $ruleData['prompt'],
   152	            name: $ruleData['name'] ?? null,
   153	            continueOnError: (bool) ($ruleData['continue_on_error'] ?? false),
   154	            sortOrder: (int) ($ruleData['sort_order'] ?? $index),
   155	            filters: $filters,
   156	            agent: isset($ruleData['agent']) ? $this->parseAgentConfig($ruleData['agent']) : null,
   157	            output: isset($ruleData['output']) ? $this->parseOutputConfig($ruleData['output']) : null,
   158	            retry: isset($ruleData['retry']) ? $this->parseRetryConfig($ruleData['retry']) : null,
   159	        );
   160	    }
   161	
   162	    /**
   163	     * Validate a single filter entry.
   164	     */
   165	    private function validateFilter(mixed $filterData, int $ruleIndex, int $filterIndex, string $filePath): FilterConfig
   166	    {
   167	        if (! is_array($filterData)) {
   168	            throw new ConfigLoadException("Filter at rules[{$ruleIndex}].filters[{$filterIndex}] must be a mapping in {$filePath}");
   169	        }
   170	
   171	        $prefix = "rules[{$ruleIndex}].filters[{$filterIndex}].";
   172	        $this->requireField($filterData, 'field', $filePath, $prefix);
   173	        $this->requireField($filterData, 'operator', $filePath, $prefix);
   174	        $this->requireField($filterData, 'value', $filePath, $prefix);
   175	
   176	        $operator = FilterOperator::tryFrom($filterData['operator']);
   177	
   178	        if ($operator === null) {
   179	            $allowed = implode(', ', array_column(FilterOperator::cases(), 'value'));
   180	            $message = "Invalid filter operator '{$filterData['operator']}' at {$prefix}operator in {$filePath}. Allowed: {$allowed}";
   181	            Log::warning($message);
   182	
   183	            throw new ConfigLoadException($message);
   184	        }
   185	
   186	        return new FilterConfig(
   187	            id: $filterData['id'] ?? null,
   188	            field: $filterData['field'],
   189	            operator: $operator,
   190	            value: (string) $filterData['value'],
   191	        );
   192	    }
   193	
   194	    /**
   195	     * Parse agent config from rule data.
   196	     *
   197	     * @param  array<string, mixed>  $data
   198	     */
   199	    private function parseAgentConfig(array $data): AgentConfig
   200	    {
   201	        return new AgentConfig(
   202	            provider: $data['provider'] ?? null,
   203	            model: $data['model'] ?? null,
   204	            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
   205	            maxSteps: isset($data['max_steps']) ? (int) $data['max_steps'] : null,
   206	            tools: $data['tools'] ?? null,
   207	            disallowedTools: $data['disallowed_tools'] ?? null,
   208	            isolation: (bool) ($data['isolation'] ?? false),
   209	        );
   210	    }
   211	
   212	    /**
   213	     * Parse output config from rule data.
   214	     *
   215	     * @param  array<string, mixed>  $data
   216	     */
   217	    private function parseOutputConfig(array $data): OutputConfig
   218	    {
   219	        return new OutputConfig(
   220	            log: (bool) ($data['log'] ?? true),
   221	            githubComment: (bool) ($data['github_comment'] ?? false),
   222	            githubReaction: $data['github_reaction'] ?? null,
   223	        );
   224	    }
   225	
   226	    /**
   227	     * Parse retry config from rule data.
   228	     *
   229	     * @param  array<string, mixed>  $data
   230	     */
   231	    private function parseRetryConfig(array $data): RetryConfig
   232	    {
   233	        return new RetryConfig(
   234	            enabled: (bool) ($data['enabled'] ?? false),
   235	            maxAttempts: (int) ($data['max_attempts'] ?? 3),
   236	            delay: (int) ($data['delay'] ?? 60),
   237	        );
   238	    }
   239	
   240	    /**
   241	     * Require a field exists in the data array.
   242	     *
   243	     * @param  array<string, mixed>  $data
   244	     */
   245	    private function requireField(array $data, string $field, string $filePath, string $prefix = ''): void
   246	    {
   247	        if (! array_key_exists($field, $data)) {
   248	            $message = "Missing required field '{$prefix}{$field}' in {$filePath}";
   249	            Log::warning($message);
   250	
   251	            throw new ConfigLoadException($message);
   252	        }
   253	    }
   254	}
```

The `ConfigLoader` parses the YAML into a hierarchy of strongly-typed DTOs. Let's see what a real `dispatch.yml` looks like — here's the one in this repo:

```bash
cat -n dispatch.yml
```

```output
     1	---
     2	version: 1
     3	agent:
     4	  name: dispatch
     5	  executor: laravel-ai
     6	  instructions_file: AGENTS.md
     7	  provider: anthropic
     8	  model: claude-sonnet-4-6
     9	  secrets:
    10	    api_key: ANTHROPIC_API_KEY
    11	cache:
    12	  config: true
    13	rules:
    14	  -
    15	    id: analyze
    16	    event: issues.labeled
    17	    name: Analyze Issue
    18	    prompt: |-
    19	      You are triaging issue #{{ event.issue.number }} on the Dispatch project.
    20	
    21	      Title: {{ event.issue.title }}
    22	      Body:
    23	      {{ event.issue.body }}
    24	
    25	      Analyze the issue and produce a detailed plan. Consider:
    26	      1. What files and components are likely involved
    27	      2. What changes are needed
    28	      3. Potential risks or edge cases
    29	      4. A step-by-step implementation plan
    30	
    31	      Write your analysis as a well-structured markdown document.
    32	    filters:
    33	      -
    34	        id: label-dispatch
    35	        field: event.label.name
    36	        operator: equals
    37	        value: dispatch
    38	    agent:
    39	      tools:
    40	        - Read
    41	        - Glob
    42	        - Grep
    43	        - Bash
    44	    output:
    45	      log: true
    46	      github_comment: true
    47	      github_reaction: eyes
    48	  -
    49	    id: implement
    50	    event: issue_comment.created
    51	    name: Implement Plan
    52	    sort_order: 1
    53	    prompt: |-
    54	      You are implementing the approved plan for issue #{{ event.issue.number }}.
    55	
    56	      Issue title: {{ event.issue.title }}
    57	      Issue body:
    58	      {{ event.issue.body }}
    59	
    60	      Trigger comment by {{ event.comment.user.login }}:
    61	      {{ event.comment.body }}
    62	
    63	      Read the issue and prior comments for the analysis and plan.
    64	      Implement the changes, commit them, and create a pull request.
    65	      Use `gh` CLI for GitHub operations (creating PRs, posting comments).
    66	    filters:
    67	      -
    68	        id: dispatch-implement
    69	        field: event.comment.body
    70	        operator: contains
    71	        value: '@dispatch implement'
    72	    agent:
    73	      tools:
    74	        - Read
    75	        - Edit
    76	        - Write
    77	        - Bash
    78	        - Glob
    79	        - Grep
    80	      isolation: true
    81	    output:
    82	      log: true
    83	      github_comment: true
    84	      github_reaction: rocket
    85	  -
    86	    id: interactive
    87	    event: issue_comment.created
    88	    name: Interactive Q&A
    89	    sort_order: 2
    90	    prompt: |-
    91	      You are responding to a question or comment on issue #{{ event.issue.number }}.
    92	
    93	      Issue title: {{ event.issue.title }}
    94	      Issue body:
    95	      {{ event.issue.body }}
    96	
    97	      Comment by {{ event.comment.user.login }}:
    98	      {{ event.comment.body }}
    99	
   100	      Respond helpfully. You can read the codebase to answer questions.
   101	      Use `gh` CLI for any GitHub interactions.
   102	    filters:
   103	      -
   104	        id: mentions-dispatch
   105	        field: event.comment.body
   106	        operator: contains
   107	        value: '@dispatch'
   108	      -
   109	        id: not-implement
   110	        field: event.comment.body
   111	        operator: not_contains
   112	        value: '@dispatch implement'
   113	    agent:
   114	      tools:
   115	        - Read
   116	        - Glob
   117	        - Grep
   118	        - Bash
   119	    output:
   120	      log: true
   121	      github_comment: true
   122	  -
   123	    id: review
   124	    event: pull_request_review_comment.created
   125	    name: Code Review Responder
   126	    prompt: |-
   127	      You are responding to a PR review comment on the Dispatch project.
   128	
   129	      PR: #{{ event.pull_request.number }} — {{ event.pull_request.title }}
   130	
   131	      Review comment by {{ event.comment.user.login }}:
   132	      {{ event.comment.body }}
   133	
   134	      File: {{ event.comment.path }}
   135	      Diff hunk:
   136	      {{ event.comment.diff_hunk }}
   137	
   138	      Respond to the review feedback. You can read the codebase for context.
   139	      Use `gh` CLI for any GitHub interactions.
   140	    filters:
   141	      -
   142	        id: mentions-dispatch
   143	        field: event.comment.body
   144	        operator: contains
   145	        value: '@dispatch'
   146	    agent:
   147	      tools:
   148	        - Read
   149	        - Glob
   150	        - Grep
   151	        - Bash
   152	    output:
   153	      log: true
   154	      github_comment: true
```

This config defines four rules:

1. **analyze** — Triggered when an issue is labeled `dispatch`. Reads the codebase and posts an analysis plan as a comment.
2. **implement** — Triggered when someone comments `@dispatch implement`. Gets full write access, creates a worktree (`isolation: true`), commits changes, and opens a PR.
3. **interactive** — Triggered by `@dispatch` mentions (but NOT `@dispatch implement` — note the second filter using `not_contains`). Read-only Q&A.
4. **review** — Triggered by PR review comments mentioning `@dispatch`. Responds to code review feedback.

The YAML is parsed into a tree of DTOs. Here's the top-level `DispatchConfig`:

```bash
cat -n app/DataTransferObjects/DispatchConfig.php
```

```output
     1	<?php
     2	
     3	namespace App\DataTransferObjects;
     4	
     5	readonly class DispatchConfig
     6	{
     7	    /**
     8	     * @param  list<RuleConfig>  $rules
     9	     * @param  array<string, string>|null  $secrets
    10	     */
    11	    public function __construct(
    12	        public int $version,
    13	        public string $agentName,
    14	        public string $agentExecutor,
    15	        public ?string $agentInstructionsFile = null,
    16	        public ?string $agentProvider = null,
    17	        public ?string $agentModel = null,
    18	        public ?array $secrets = null,
    19	        public bool $cacheConfig = false,
    20	        public array $rules = [],
    21	    ) {}
    22	}
```

And the `RuleConfig` it contains, which is the richest DTO — each rule carries its own agent, output, and retry config:

```bash
cat -n app/DataTransferObjects/RuleConfig.php
```

```output
     1	<?php
     2	
     3	namespace App\DataTransferObjects;
     4	
     5	readonly class RuleConfig
     6	{
     7	    /**
     8	     * @param  list<FilterConfig>  $filters
     9	     */
    10	    public function __construct(
    11	        public string $id,
    12	        public string $event,
    13	        public string $prompt,
    14	        public ?string $name = null,
    15	        public bool $continueOnError = false,
    16	        public int $sortOrder = 0,
    17	        public array $filters = [],
    18	        public ?AgentConfig $agent = null,
    19	        public ?OutputConfig $output = null,
    20	        public ?RetryConfig $retry = null,
    21	    ) {}
    22	}
```

## Step 3: Rule Matching

With the config loaded, the `RuleMatchingEngine` evaluates which rules match the incoming event. This is a two-phase process: first filter by event type, then evaluate field-level filters:

```bash
cat -n app/Services/RuleMatchingEngine.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\DataTransferObjects\FilterConfig;
     6	use App\DataTransferObjects\RuleConfig;
     7	use App\Enums\FilterOperator;
     8	use App\Exceptions\RuleMatchingException;
     9	use App\Models\Project;
    10	use Illuminate\Support\Arr;
    11	use Illuminate\Support\Collection;
    12	use Illuminate\Support\Facades\Log;
    13	
    14	class RuleMatchingEngine
    15	{
    16	    public function __construct(
    17	        protected ConfigLoader $configLoader,
    18	    ) {}
    19	
    20	    /**
    21	     * Match rules for a given repo and event type against the webhook payload.
    22	     *
    23	     * @param  array<string, mixed>  $payload
    24	     * @return Collection<int, RuleConfig>
    25	     *
    26	     * @throws RuleMatchingException
    27	     */
    28	    public function match(string $repo, string $eventType, array $payload): Collection
    29	    {
    30	        $project = Project::where('repo', $repo)->first();
    31	
    32	        if (! $project) {
    33	            Log::error("Rule matching: project not found for repo '{$repo}'");
    34	
    35	            throw new RuleMatchingException("Project not found for repo '{$repo}'");
    36	        }
    37	
    38	        if (! $project->path) {
    39	            throw new RuleMatchingException("Project '{$repo}' has no local path configured");
    40	        }
    41	
    42	        try {
    43	            $config = $this->configLoader->load($project->path);
    44	        } catch (\Throwable $e) {
    45	            throw new RuleMatchingException("Failed to load dispatch.yml for '{$repo}': {$e->getMessage()}");
    46	        }
    47	
    48	        $rules = collect($config->rules)
    49	            ->filter(fn (RuleConfig $rule) => $rule->event === $eventType);
    50	
    51	        if ($rules->isEmpty()) {
    52	            return collect();
    53	        }
    54	
    55	        return $rules->filter(fn (RuleConfig $rule) => $this->evaluateFilters($rule, $payload))
    56	            ->values();
    57	    }
    58	
    59	    /**
    60	     * Evaluate all filters on a rule against the payload (AND logic).
    61	     *
    62	     * @param  array<string, mixed>  $payload
    63	     */
    64	    protected function evaluateFilters(RuleConfig $rule, array $payload): bool
    65	    {
    66	        if (empty($rule->filters)) {
    67	            return true;
    68	        }
    69	
    70	        foreach ($rule->filters as $filter) {
    71	            if (! $this->evaluateFilter($filter, $payload)) {
    72	                return false;
    73	            }
    74	        }
    75	
    76	        return true;
    77	    }
    78	
    79	    /**
    80	     * Evaluate a single filter against the payload.
    81	     *
    82	     * @param  array<string, mixed>  $payload
    83	     */
    84	    protected function evaluateFilter(FilterConfig $filter, array $payload): bool
    85	    {
    86	        $fieldValue = $this->resolveFieldPath($filter->field, $payload);
    87	
    88	        return $this->applyOperator($filter->operator, $fieldValue, $filter->value);
    89	    }
    90	
    91	    /**
    92	     * Resolve a dot-path field against the payload.
    93	     * The field may have an "event." prefix which is stripped.
    94	     *
    95	     * @param  array<string, mixed>  $payload
    96	     */
    97	    protected function resolveFieldPath(string $field, array $payload): mixed
    98	    {
    99	        if (str_starts_with($field, 'event.')) {
   100	            $field = substr($field, 6);
   101	        }
   102	
   103	        return Arr::get($payload, $field);
   104	    }
   105	
   106	    /**
   107	     * Apply a filter operator to compare field value against expected value.
   108	     */
   109	    protected function applyOperator(FilterOperator $operator, mixed $fieldValue, string $expectedValue): bool
   110	    {
   111	        $fieldString = (string) ($fieldValue ?? '');
   112	
   113	        return match ($operator) {
   114	            FilterOperator::Equals => $fieldString === $expectedValue,
   115	            FilterOperator::NotEquals => $fieldString !== $expectedValue,
   116	            FilterOperator::Contains => str_contains($fieldString, $expectedValue),
   117	            FilterOperator::NotContains => ! str_contains($fieldString, $expectedValue),
   118	            FilterOperator::StartsWith => str_starts_with($fieldString, $expectedValue),
   119	            FilterOperator::EndsWith => str_ends_with($fieldString, $expectedValue),
   120	            FilterOperator::Matches => $this->safeRegexMatch($expectedValue, $fieldString),
   121	        };
   122	    }
   123	
   124	    /**
   125	     * Safely evaluate a regex match, returning false on invalid patterns.
   126	     */
   127	    protected function safeRegexMatch(string $pattern, string $subject): bool
   128	    {
   129	        try {
   130	            $result = @preg_match($pattern, $subject);
   131	        } catch (\Throwable) {
   132	            Log::warning("RuleMatchingEngine: invalid regex pattern '{$pattern}'");
   133	
   134	            return false;
   135	        }
   136	
   137	        if ($result === false) {
   138	            Log::warning("RuleMatchingEngine: invalid regex pattern '{$pattern}'");
   139	
   140	            return false;
   141	        }
   142	
   143	        return (bool) $result;
   144	    }
   145	}
```

The matching logic is straightforward:

1. **Event type filter** (line 48-49): Only rules whose `event` field exactly matches the incoming event type (e.g., `issues.labeled`) pass through.
2. **Field filters** (line 55, AND logic): Every filter on a rule must pass. A rule with no filters matches unconditionally (line 66-67).
3. **Field resolution** (line 97-104): Filter fields use dot notation into the webhook payload. The `event.` prefix is stripped for convenience — so `event.label.name` resolves to `payload['label']['name']`.
4. **Operators** (line 109-121): Seven operators via a PHP enum, including regex with safe error handling.

Here's the `FilterOperator` enum:

```bash
cat -n app/Enums/FilterOperator.php
```

```output
     1	<?php
     2	
     3	namespace App\Enums;
     4	
     5	enum FilterOperator: string
     6	{
     7	    case Equals = 'equals';
     8	    case NotEquals = 'not_equals';
     9	    case Contains = 'contains';
    10	    case NotContains = 'not_contains';
    11	    case StartsWith = 'starts_with';
    12	    case EndsWith = 'ends_with';
    13	    case Matches = 'matches';
    14	}
```

## Step 4: Agent Dispatch

Once rules are matched, the `AgentDispatcher` creates an `AgentRun` record for each and queues a job. This is where the pipeline pattern emerges — rules can be configured to halt the pipeline on failure:

```bash
cat -n app/Services/AgentDispatcher.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\DataTransferObjects\DispatchConfig;
     6	use App\DataTransferObjects\RuleConfig;
     7	use App\Jobs\ProcessAgentRun;
     8	use App\Models\AgentRun;
     9	use App\Models\Project;
    10	use App\Models\WebhookLog;
    11	use Illuminate\Support\Collection;
    12	
    13	class AgentDispatcher
    14	{
    15	    public function __construct(
    16	        protected PromptRenderer $promptRenderer,
    17	    ) {}
    18	
    19	    /**
    20	     * Dispatch agent jobs for matched rules.
    21	     *
    22	     * @param  Collection<int, RuleConfig>  $matchedRules
    23	     * @param  array<string, mixed>  $payload
    24	     * @return array<int, array<string, mixed>>
    25	     */
    26	    public function dispatch(WebhookLog $webhookLog, Collection $matchedRules, array $payload, Project $project, DispatchConfig $config): array
    27	    {
    28	        $results = [];
    29	        $haltPipeline = false;
    30	
    31	        foreach ($matchedRules as $rule) {
    32	            if ($haltPipeline) {
    33	                $agentRun = AgentRun::create([
    34	                    'webhook_log_id' => $webhookLog->id,
    35	                    'rule_id' => $rule->id,
    36	                    'status' => 'skipped',
    37	                    'error' => 'Skipped due to a previous rule failure',
    38	                    'created_at' => now(),
    39	                ]);
    40	
    41	                $results[] = [
    42	                    'rule' => $rule->id,
    43	                    'name' => $rule->name,
    44	                    'status' => 'skipped',
    45	                    'reason' => 'previous_failure',
    46	                    'agent_run_id' => $agentRun->id,
    47	                ];
    48	
    49	                continue;
    50	            }
    51	
    52	            $agentRun = AgentRun::create([
    53	                'webhook_log_id' => $webhookLog->id,
    54	                'rule_id' => $rule->id,
    55	                'status' => 'queued',
    56	                'created_at' => now(),
    57	            ]);
    58	
    59	            ProcessAgentRun::dispatch($agentRun, $rule, $payload, $project, $config);
    60	
    61	            $results[] = [
    62	                'rule' => $rule->id,
    63	                'name' => $rule->name,
    64	                'status' => 'queued',
    65	                'agent_run_id' => $agentRun->id,
    66	            ];
    67	
    68	            // Pipeline halting only works with sync queue driver (e.g. in tests).
    69	            if (config('queue.default') === 'sync') {
    70	                $agentRun->refresh();
    71	                if (! $rule->continueOnError && $agentRun->status === 'failed') {
    72	                    $haltPipeline = true;
    73	                    $results[array_key_last($results)]['status'] = 'failed';
    74	                }
    75	            }
    76	        }
    77	
    78	        return $results;
    79	    }
    80	}
```

The dispatcher iterates matched rules in sort order and queues a `ProcessAgentRun` job for each. The pipeline halting logic (line 69-75) only applies with the sync queue driver (used in tests) — with Redis, jobs run asynchronously so halting isn't possible at dispatch time.

## Step 5: Job Execution

The `ProcessAgentRun` job is where the real work happens. It's the longest and most complex piece of the system — it coordinates worktree creation, conversation history, prompt rendering, executor selection, and output routing:

```bash
cat -n app/Jobs/ProcessAgentRun.php
```

```output
     1	<?php
     2	
     3	namespace App\Jobs;
     4	
     5	use App\Contracts\Executor;
     6	use App\DataTransferObjects\DispatchConfig;
     7	use App\DataTransferObjects\OutputConfig;
     8	use App\DataTransferObjects\RuleConfig;
     9	use App\Executors\ClaudeCliExecutor;
    10	use App\Executors\LaravelAiExecutor;
    11	use App\Models\AgentRun;
    12	use App\Models\Project;
    13	use App\Services\ConversationMemory;
    14	use App\Services\OutputHandler;
    15	use App\Services\PromptRenderer;
    16	use App\Services\WorktreeManager;
    17	use Illuminate\Contracts\Queue\ShouldQueue;
    18	use Illuminate\Foundation\Queue\Queueable;
    19	use Illuminate\Support\Facades\Log;
    20	
    21	class ProcessAgentRun implements ShouldQueue
    22	{
    23	    use Queueable;
    24	
    25	    public int $tries = 1;
    26	
    27	    public int $backoff = 0;
    28	
    29	    /**
    30	     * @param  array<string, mixed>  $payload
    31	     */
    32	    public function __construct(
    33	        public AgentRun $agentRun,
    34	        public RuleConfig $ruleConfig,
    35	        public array $payload,
    36	        public Project $project,
    37	        public DispatchConfig $dispatchConfig,
    38	    ) {
    39	        $this->onQueue('agents');
    40	        $this->configureRetry();
    41	    }
    42	
    43	    /**
    44	     * Execute the job.
    45	     */
    46	    public function handle(): void
    47	    {
    48	        $attempt = $this->attempts();
    49	        $this->agentRun->update([
    50	            'status' => 'running',
    51	            'attempt' => $attempt,
    52	        ]);
    53	
    54	        // Add reaction immediately so the user knows the agent is working
    55	        $outputConfig = $this->ruleConfig->output ?? new OutputConfig;
    56	        if ($outputConfig->githubReaction) {
    57	            app(OutputHandler::class)->addReaction(
    58	                $outputConfig->githubReaction,
    59	                $this->payload,
    60	            );
    61	        }
    62	
    63	        $executor = $this->resolveExecutor();
    64	        $renderedPrompt = app(PromptRenderer::class)->render(
    65	            $this->ruleConfig->prompt,
    66	            $this->payload,
    67	        );
    68	        $agentConfig = $this->resolveAgentConfig();
    69	        $conversationHistory = $this->loadConversationHistory();
    70	
    71	        $worktree = null;
    72	
    73	        if ($agentConfig['isolation']) {
    74	            $worktree = $this->createWorktree($agentConfig);
    75	            $agentConfig['project_path'] = $worktree['path'];
    76	        }
    77	
    78	        try {
    79	            $result = $executor->execute($this->agentRun, $renderedPrompt, $agentConfig, $conversationHistory);
    80	
    81	            $this->agentRun->update([
    82	                'status' => $result->status,
    83	                'output' => $result->output,
    84	                'steps' => $result->steps,
    85	                'tokens_used' => $result->tokensUsed,
    86	                'cost' => $result->cost,
    87	                'duration_ms' => $result->durationMs,
    88	                'error' => $result->error,
    89	            ]);
    90	
    91	            if ($result->status === 'success') {
    92	                app(OutputHandler::class)->handle(
    93	                    $this->agentRun,
    94	                    $outputConfig,
    95	                    $this->payload,
    96	                );
    97	            } elseif ($result->status === 'failed' && $this->shouldRetry()) {
    98	                throw new \RuntimeException($result->error ?? 'Agent execution failed');
    99	            }
   100	        } finally {
   101	            if ($worktree) {
   102	                $this->cleanupWorktree($worktree, $agentConfig);
   103	            }
   104	        }
   105	    }
   106	
   107	    /**
   108	     * Handle a job failure (called only after all retries are exhausted).
   109	     */
   110	    public function failed(?\Throwable $exception): void
   111	    {
   112	        $this->agentRun->update([
   113	            'status' => 'failed',
   114	            'attempt' => $this->attempts(),
   115	            'error' => $exception?->getMessage(),
   116	        ]);
   117	    }
   118	
   119	    /**
   120	     * Configure retry behavior from the rule's retry config.
   121	     */
   122	    protected function configureRetry(): void
   123	    {
   124	        $retryConfig = $this->ruleConfig->retry;
   125	
   126	        if ($retryConfig && $retryConfig->enabled) {
   127	            $this->tries = $retryConfig->maxAttempts;
   128	            $this->backoff = $retryConfig->delay;
   129	        }
   130	    }
   131	
   132	    /**
   133	     * Determine if the job should be retried (more attempts remaining).
   134	     */
   135	    protected function shouldRetry(): bool
   136	    {
   137	        return $this->tries > 1 && $this->attempts() < $this->tries;
   138	    }
   139	
   140	    /**
   141	     * Load conversation history for the current thread.
   142	     *
   143	     * @return list<array{role: string, content: string}>
   144	     */
   145	    protected function loadConversationHistory(): array
   146	    {
   147	        $memory = app(ConversationMemory::class);
   148	        $threadKey = $memory->deriveThreadKey($this->payload);
   149	
   150	        if (! $threadKey) {
   151	            return [];
   152	        }
   153	
   154	        return $memory->retrieveHistory($threadKey, $this->agentRun->id);
   155	    }
   156	
   157	    /**
   158	     * Resolve the executor based on the dispatch config.
   159	     */
   160	    protected function resolveExecutor(): Executor
   161	    {
   162	        $executor = $this->dispatchConfig->agentExecutor;
   163	
   164	        return match ($executor) {
   165	            'claude-cli' => app(ClaudeCliExecutor::class),
   166	            'laravel-ai' => app(LaravelAiExecutor::class),
   167	            default => app(LaravelAiExecutor::class),
   168	        };
   169	    }
   170	
   171	    /**
   172	     * Resolve agent config by merging rule-level config with dispatch-level defaults.
   173	     *
   174	     * @return array<string, mixed>
   175	     */
   176	    protected function resolveAgentConfig(): array
   177	    {
   178	        $agent = $this->ruleConfig->agent;
   179	        $output = $this->ruleConfig->output;
   180	
   181	        return [
   182	            'provider' => $agent?->provider ?? $this->dispatchConfig->agentProvider,
   183	            'model' => $agent?->model ?? $this->dispatchConfig->agentModel,
   184	            'max_tokens' => $agent?->maxTokens,
   185	            'max_steps' => $agent?->maxSteps,
   186	            'tools' => $agent?->tools ?? [],
   187	            'disallowed_tools' => $agent?->disallowedTools ?? [],
   188	            'isolation' => $agent?->isolation ?? false,
   189	            'instructions_file' => $this->dispatchConfig->agentInstructionsFile,
   190	            'project_path' => $this->project->path,
   191	            'output_github_comment' => $output?->githubComment ?? false,
   192	            'output_github_reaction' => $output?->githubReaction,
   193	        ];
   194	    }
   195	
   196	    /**
   197	     * Create a git worktree for isolated execution.
   198	     *
   199	     * @param  array<string, mixed>  $agentConfig
   200	     * @return array{path: string, branch: string}
   201	     */
   202	    protected function createWorktree(array $agentConfig): array
   203	    {
   204	        $worktreeManager = app(WorktreeManager::class);
   205	        $projectPath = $agentConfig['project_path'];
   206	        $ruleId = $this->ruleConfig->id;
   207	
   208	        $worktree = $worktreeManager->create($projectPath, $ruleId);
   209	
   210	        Log::info("Created worktree for rule {$ruleId}", [
   211	            'path' => $worktree['path'],
   212	            'branch' => $worktree['branch'],
   213	        ]);
   214	
   215	        return $worktree;
   216	    }
   217	
   218	    /**
   219	     * Clean up a worktree after execution.
   220	     *
   221	     * @param  array{path: string, branch: string}  $worktree
   222	     * @param  array<string, mixed>  $agentConfig
   223	     */
   224	    protected function cleanupWorktree(array $worktree, array $agentConfig): void
   225	    {
   226	        $worktreeManager = app(WorktreeManager::class);
   227	        $projectPath = $this->project->path ?? $agentConfig['project_path'];
   228	
   229	        $removed = $worktreeManager->cleanup(
   230	            $worktree['path'],
   231	            $worktree['branch'],
   232	            $projectPath,
   233	        );
   234	
   235	        $ruleId = $this->ruleConfig->id;
   236	
   237	        if ($removed) {
   238	            Log::info("Cleaned up worktree for rule {$ruleId} (no new commits)");
   239	        } else {
   240	            Log::info("Retained worktree for rule {$ruleId} (commits found)", [
   241	                'path' => $worktree['path'],
   242	                'branch' => $worktree['branch'],
   243	            ]);
   244	        }
   245	    }
   246	}
```

This is the orchestrator. The key sequence in `handle()`:

1. **Mark running** (line 49-52) and **add reaction** (line 55-61) — immediately visible on GitHub so the user knows something is happening.
2. **Resolve executor** (line 63) — picks between Laravel AI SDK or Claude CLI based on `dispatch.yml`.
3. **Render prompt** (line 64-67) — replaces `{{ event.* }}` placeholders with actual payload values.
4. **Load conversation history** (line 69) — retrieves prior exchanges on the same issue/PR thread.
5. **Create worktree** (line 73-76) — if `isolation: true`, the agent works in a separate git worktree so it can make commits without touching the main checkout.
6. **Execute** (line 79) — delegates to the chosen executor.
7. **Route output** (line 91-96) — on success, posts the agent's response as a GitHub comment.
8. **Cleanup** (line 100-104) — in the `finally` block, removes the worktree if the agent didn't commit anything; retains it if there are new commits (for PR creation).

Let's look at the supporting services used here, starting with prompt rendering:

```bash
cat -n app/Services/PromptRenderer.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use Illuminate\Support\Arr;
     6	
     7	class PromptRenderer
     8	{
     9	    /**
    10	     * Render a prompt template by replacing {{ event.field.path }} placeholders
    11	     * with values resolved from the webhook payload.
    12	     *
    13	     * @param  array<string, mixed>  $payload
    14	     */
    15	    public function render(string $template, array $payload): string
    16	    {
    17	        return preg_replace_callback('/\{\{\s*event\.([^}]+?)\s*\}\}/', function (array $matches) use ($payload) {
    18	            $path = trim($matches[1]);
    19	
    20	            return (string) Arr::get($payload, $path, '');
    21	        }, $template);
    22	    }
    23	}
```

Simple and effective — a regex finds all `{{ event.* }}` placeholders and resolves them via Laravel's `Arr::get()` with dot notation. For example, `{{ event.issue.title }}` becomes `Arr::get($payload, 'issue.title')`.

Next, conversation memory — this is how the agent maintains context across multiple interactions on the same issue thread:

```bash
cat -n app/Services/ConversationMemory.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\Models\AgentRun;
     6	use Illuminate\Support\Arr;
     7	
     8	class ConversationMemory
     9	{
    10	    /**
    11	     * Derive a conversation thread key from the webhook payload.
    12	     *
    13	     * Format: {repo}:{resource_type}:{resource_number}
    14	     * Example: owner/repo:issue:123, owner/repo:pr:456, owner/repo:discussion:789
    15	     *
    16	     * @param  array<string, mixed>  $payload
    17	     */
    18	    public function deriveThreadKey(array $payload): ?string
    19	    {
    20	        $repo = Arr::get($payload, 'repository.full_name');
    21	
    22	        if (! $repo) {
    23	            return null;
    24	        }
    25	
    26	        if ($number = Arr::get($payload, 'pull_request.number')) {
    27	            return "{$repo}:pr:{$number}";
    28	        }
    29	
    30	        if ($number = Arr::get($payload, 'issue.number')) {
    31	            return "{$repo}:issue:{$number}";
    32	        }
    33	
    34	        if ($number = Arr::get($payload, 'discussion.number')) {
    35	            return "{$repo}:discussion:{$number}";
    36	        }
    37	
    38	        return null;
    39	    }
    40	
    41	    /**
    42	     * Retrieve prior conversation history for a thread.
    43	     *
    44	     * Returns prior agent runs (with prompts and outputs) for the same thread key,
    45	     * ordered chronologically.
    46	     *
    47	     * @param  array<string, mixed>  $payload
    48	     * @return list<array{role: string, content: string}>
    49	     */
    50	    public function retrieveHistory(string $threadKey, ?int $excludeRunId = null): array
    51	    {
    52	        $parts = explode(':', $threadKey, 3);
    53	
    54	        if (count($parts) !== 3) {
    55	            return [];
    56	        }
    57	
    58	        [$repo, $resourceType, $resourceNumber] = $parts;
    59	
    60	        $payloadField = match ($resourceType) {
    61	            'pr' => 'pull_request.number',
    62	            'issue' => 'issue.number',
    63	            'discussion' => 'discussion.number',
    64	            default => null,
    65	        };
    66	
    67	        if (! $payloadField) {
    68	            return [];
    69	        }
    70	
    71	        $query = AgentRun::query()
    72	            ->whereHas('webhookLog', function ($q) use ($repo) {
    73	                $q->where('repo', $repo);
    74	            })
    75	            ->where('status', 'success')
    76	            ->whereNotNull('output')
    77	            ->orderBy('created_at', 'asc')
    78	            ->orderBy('id', 'asc');
    79	
    80	        if ($excludeRunId) {
    81	            $query->where('id', '!=', $excludeRunId);
    82	        }
    83	
    84	        $runs = $query->with('webhookLog')->get();
    85	
    86	        $messages = [];
    87	
    88	        foreach ($runs as $run) {
    89	            $runPayload = $run->webhookLog->payload ?? [];
    90	            $runThreadKey = $this->deriveThreadKey($runPayload);
    91	
    92	            if ($runThreadKey !== $threadKey) {
    93	                continue;
    94	            }
    95	
    96	            // Add the prompt that was sent (stored on the rule, rendered with payload)
    97	            if ($run->webhookLog) {
    98	                $messages[] = [
    99	                    'role' => 'user',
   100	                    'content' => $this->buildUserContext($run),
   101	                ];
   102	            }
   103	
   104	            // Add the agent's response
   105	            $messages[] = [
   106	                'role' => 'assistant',
   107	                'content' => $run->output,
   108	            ];
   109	        }
   110	
   111	        return $messages;
   112	    }
   113	
   114	    /**
   115	     * Format conversation history as text for CLI executors.
   116	     *
   117	     * @param  list<array{role: string, content: string}>  $messages
   118	     */
   119	    public function formatAsText(array $messages): string
   120	    {
   121	        if (empty($messages)) {
   122	            return '';
   123	        }
   124	
   125	        $formatted = "## Prior Conversation History\n\n";
   126	
   127	        foreach ($messages as $message) {
   128	            $label = $message['role'] === 'user' ? 'User' : 'Assistant';
   129	            $formatted .= "### {$label}\n{$message['content']}\n\n";
   130	        }
   131	
   132	        return $formatted;
   133	    }
   134	
   135	    /**
   136	     * Build user context string from an agent run's webhook log.
   137	     */
   138	    protected function buildUserContext(AgentRun $run): string
   139	    {
   140	        $payload = $run->webhookLog->payload ?? [];
   141	        $eventType = $run->webhookLog->event_type ?? 'unknown';
   142	        $ruleId = $run->rule_id ?? 'unknown';
   143	
   144	        $parts = ["[Event: {$eventType}, Rule: {$ruleId}]"];
   145	
   146	        $body = Arr::get($payload, 'comment.body')
   147	            ?? Arr::get($payload, 'issue.body')
   148	            ?? Arr::get($payload, 'pull_request.body')
   149	            ?? Arr::get($payload, 'discussion.body');
   150	
   151	        if ($body) {
   152	            $parts[] = $body;
   153	        }
   154	
   155	        return implode("\n", $parts);
   156	    }
   157	}
```

Conversation memory derives a thread key like `datashaman/dispatch:issue:42` from the payload, then queries all prior successful `AgentRun` records on that thread. This lets the agent see what it (or other rules) previously said, maintaining coherent multi-turn conversations within an issue or PR.

Now let's look at the worktree manager — the isolation mechanism that lets agents make commits safely:

```bash
cat -n app/Services/WorktreeManager.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use Illuminate\Support\Facades\File;
     6	use Illuminate\Support\Facades\Process;
     7	use Illuminate\Support\Str;
     8	use RuntimeException;
     9	
    10	class WorktreeManager
    11	{
    12	    /**
    13	     * Create a temporary git worktree for isolated agent execution.
    14	     *
    15	     * @return array{path: string, branch: string}
    16	     */
    17	    public function create(string $projectPath, string $ruleId): array
    18	    {
    19	        $safeRuleId = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $ruleId);
    20	        $shortHash = Str::random(8);
    21	        $branch = "dispatch/{$safeRuleId}/{$shortHash}";
    22	        $worktreeBase = rtrim($projectPath, '/').'/.worktrees';
    23	        $worktreePath = $worktreeBase.'/'.$safeRuleId.'-'.$shortHash;
    24	
    25	        File::ensureDirectoryExists($worktreeBase);
    26	
    27	        $result = Process::path($projectPath)
    28	            ->run(['git', 'worktree', 'add', '-b', $branch, $worktreePath]);
    29	
    30	        if (! $result->successful()) {
    31	            throw new RuntimeException(
    32	                "Failed to create git worktree: {$result->errorOutput()}"
    33	            );
    34	        }
    35	
    36	        return [
    37	            'path' => $worktreePath,
    38	            'branch' => $branch,
    39	        ];
    40	    }
    41	
    42	    /**
    43	     * Check if any new commits were made in the worktree since it was created.
    44	     */
    45	    public function hasNewCommits(string $worktreePath, string $projectPath): bool
    46	    {
    47	        // Get the HEAD of the main repo
    48	        $mainHead = Process::path($projectPath)
    49	            ->run(['git', 'rev-parse', 'HEAD']);
    50	
    51	        // Get the HEAD of the worktree
    52	        $worktreeHead = Process::path($worktreePath)
    53	            ->run(['git', 'rev-parse', 'HEAD']);
    54	
    55	        if (! $mainHead->successful() || ! $worktreeHead->successful()) {
    56	            return false;
    57	        }
    58	
    59	        return trim($mainHead->output()) !== trim($worktreeHead->output());
    60	    }
    61	
    62	    /**
    63	     * Remove a worktree and its branch.
    64	     */
    65	    public function remove(string $worktreePath, string $branch, string $projectPath): void
    66	    {
    67	        // Remove the worktree
    68	        Process::path($projectPath)
    69	            ->run(['git', 'worktree', 'remove', $worktreePath, '--force']);
    70	
    71	        // Delete the branch
    72	        Process::path($projectPath)
    73	            ->run(['git', 'branch', '-D', $branch]);
    74	    }
    75	
    76	    /**
    77	     * Clean up a worktree after execution.
    78	     * Removes it if no new commits were made, leaves it in place otherwise.
    79	     *
    80	     * @return bool True if the worktree was removed, false if retained.
    81	     */
    82	    public function cleanup(string $worktreePath, string $branch, string $projectPath): bool
    83	    {
    84	        if ($this->hasNewCommits($worktreePath, $projectPath)) {
    85	            return false;
    86	        }
    87	
    88	        $this->remove($worktreePath, $branch, $projectPath);
    89	
    90	        return true;
    91	    }
    92	}
```

The worktree manager creates isolated branches named `dispatch/{rule-id}/{random-hash}` under a `.worktrees/` directory in the project. After execution, it compares HEAD commits — if the agent made commits, the worktree is retained (for PR creation); if not, it's cleaned up.

## Step 6: Executors

Dispatch supports two execution backends. Let's start with the Laravel AI SDK executor, which uses the `laravel/ai` package to run a structured agent with tool use:

```bash
cat -n app/Contracts/Executor.php
```

```output
     1	<?php
     2	
     3	namespace App\Contracts;
     4	
     5	use App\DataTransferObjects\ExecutionResult;
     6	use App\Models\AgentRun;
     7	
     8	interface Executor
     9	{
    10	    /**
    11	     * Execute an agent run with the given rendered prompt and resolved agent config.
    12	     *
    13	     * @param  array<string, mixed>  $agentConfig  Resolved agent config with keys: provider, model, max_tokens, tools, disallowed_tools, isolation, instructions_file, project_path
    14	     * @param  list<array{role: string, content: string}>  $conversationHistory  Prior conversation messages for thread context
    15	     */
    16	    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult;
    17	}
```

```bash
cat -n app/Executors/LaravelAiExecutor.php
```

```output
     1	<?php
     2	
     3	namespace App\Executors;
     4	
     5	use App\Ai\Agents\DispatchAgent;
     6	use App\Contracts\Executor;
     7	use App\DataTransferObjects\ExecutionResult;
     8	use App\Models\AgentRun;
     9	use App\Services\ToolRegistry;
    10	use Illuminate\Support\Facades\File;
    11	use Laravel\Ai\Contracts\Tool;
    12	use Throwable;
    13	
    14	class LaravelAiExecutor implements Executor
    15	{
    16	    public function __construct(
    17	        protected ToolRegistry $toolRegistry,
    18	    ) {}
    19	
    20	    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    21	    {
    22	        $startTime = hrtime(true);
    23	
    24	        try {
    25	            $systemPrompt = $this->loadSystemPrompt($agentConfig).$this->buildOutputInstructions($agentConfig);
    26	            $tools = $this->resolveTools($agentConfig);
    27	
    28	            $agent = new DispatchAgent(
    29	                systemPrompt: $systemPrompt,
    30	                agentTools: $tools,
    31	            );
    32	
    33	            if (! empty($conversationHistory)) {
    34	                $agent->withConversationHistory($conversationHistory);
    35	            }
    36	
    37	            $provider = $agentConfig['provider'] ?? null;
    38	            $model = $agentConfig['model'] ?? null;
    39	
    40	            $response = $agent->prompt(
    41	                prompt: $renderedPrompt,
    42	                provider: $provider,
    43	                model: $model,
    44	            );
    45	
    46	            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
    47	            $tokensUsed = $response->usage->promptTokens + $response->usage->completionTokens;
    48	
    49	            $steps = $response->steps->map(fn ($step) => $step->toArray())->toArray();
    50	
    51	            return new ExecutionResult(
    52	                status: 'success',
    53	                output: $response->text,
    54	                steps: $steps ?: null,
    55	                tokensUsed: $tokensUsed,
    56	                durationMs: $durationMs,
    57	            );
    58	        } catch (Throwable $e) {
    59	            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
    60	
    61	            return new ExecutionResult(
    62	                status: 'failed',
    63	                error: $e->getMessage(),
    64	                durationMs: $durationMs,
    65	            );
    66	        }
    67	    }
    68	
    69	    /**
    70	     * Load the system prompt from the instructions file, or use a default.
    71	     *
    72	     * @param  array<string, mixed>  $agentConfig
    73	     */
    74	    protected function loadSystemPrompt(array $agentConfig): string
    75	    {
    76	        $instructionsFile = $agentConfig['instructions_file'] ?? null;
    77	        $projectPath = $agentConfig['project_path'] ?? null;
    78	
    79	        if ($instructionsFile && $projectPath) {
    80	            $fullPath = rtrim($projectPath, '/').'/'.$instructionsFile;
    81	
    82	            if (File::exists($fullPath)) {
    83	                return File::get($fullPath);
    84	            }
    85	        }
    86	
    87	        return 'You are a helpful AI assistant.';
    88	    }
    89	
    90	    /**
    91	     * Build output routing instructions to append to the system prompt.
    92	     *
    93	     * @param  array<string, mixed>  $agentConfig
    94	     */
    95	    protected function buildOutputInstructions(array $agentConfig): string
    96	    {
    97	        $lines = [];
    98	
    99	        $lines[] = 'You have a limited number of tool-use steps. Do NOT exhaustively read every file — be strategic.';
   100	        $lines[] = 'Gather only what you need, then write your final response. Your text response is the deliverable.';
   101	
   102	        if ($agentConfig['output_github_comment'] ?? false) {
   103	            $lines[] = 'Your final text response will be posted as a GitHub comment automatically.';
   104	            $lines[] = 'Do NOT use `gh issue comment`, `gh pr comment`, or `gh api` to post comments — just write your response directly.';
   105	            $lines[] = 'Do not narrate what you did — output only the deliverable itself.';
   106	        }
   107	
   108	        if ($agentConfig['output_github_reaction'] ?? null) {
   109	            $lines[] = 'A "'.$agentConfig['output_github_reaction'].'" reaction will be added automatically. Do not add reactions yourself.';
   110	        }
   111	
   112	        return "\n\n## Output Routing\n\n".implode("\n", $lines);
   113	    }
   114	
   115	    /**
   116	     * Resolve tool instances from the agent config.
   117	     *
   118	     * @param  array<string, mixed>  $agentConfig
   119	     * @return list<Tool>
   120	     */
   121	    protected function resolveTools(array $agentConfig): array
   122	    {
   123	        $workingDirectory = $agentConfig['project_path'] ?? '';
   124	
   125	        return $this->toolRegistry->resolve(
   126	            tools: $agentConfig['tools'] ?? [],
   127	            workingDirectory: $workingDirectory,
   128	        );
   129	    }
   130	}
```

The Laravel AI executor:

1. **Loads a system prompt** from the project's `AGENTS.md` (or whatever `instructions_file` is configured), then appends output routing instructions — crucially telling the agent NOT to post comments itself since the system handles that.
2. **Resolves tools** via the `ToolRegistry`, passing the working directory (which may be a worktree path).
3. **Creates a `DispatchAgent`** and calls `prompt()` with the rendered prompt, provider, and model.
4. **Returns an `ExecutionResult`** with the response text, token usage, and step history.

Now the Claude CLI executor — this shells out to the `claude` CLI binary directly:

```bash
cat -n app/Executors/ClaudeCliExecutor.php
```

```output
     1	<?php
     2	
     3	namespace App\Executors;
     4	
     5	use App\Contracts\Executor;
     6	use App\DataTransferObjects\ExecutionResult;
     7	use App\Models\AgentRun;
     8	use App\Services\ConversationMemory;
     9	use Illuminate\Support\Facades\File;
    10	use Illuminate\Support\Facades\Log;
    11	use Illuminate\Support\Facades\Process;
    12	use Throwable;
    13	
    14	class ClaudeCliExecutor implements Executor
    15	{
    16	    public function execute(AgentRun $run, string $renderedPrompt, array $agentConfig, array $conversationHistory = []): ExecutionResult
    17	    {
    18	        $startTime = hrtime(true);
    19	
    20	        try {
    21	            $promptWithHistory = $this->prependConversationHistory($renderedPrompt, $conversationHistory);
    22	            $command = $this->buildCommand($promptWithHistory, $agentConfig);
    23	            $workingDirectory = $agentConfig['project_path'] ?? getcwd();
    24	
    25	            Log::info('ClaudeCliExecutor: starting execution', [
    26	                'agent_run_id' => $run->id,
    27	                'working_directory' => $workingDirectory,
    28	                'command_args' => $this->redactCommand($command),
    29	                'prompt_length' => strlen($renderedPrompt),
    30	                'history_entries' => count($conversationHistory),
    31	                'model' => $agentConfig['model'] ?? 'default',
    32	                'tools' => $agentConfig['tools'] ?? [],
    33	                'disallowed_tools' => $agentConfig['disallowed_tools'] ?? [],
    34	            ]);
    35	
    36	            Log::info('ClaudeCliExecutor: running command', [
    37	                'agent_run_id' => $run->id,
    38	                'full_command' => implode(' ', array_map('escapeshellarg', $command)),
    39	            ]);
    40	
    41	            $result = Process::path($workingDirectory)
    42	                ->timeout(600)
    43	                ->run($command);
    44	
    45	            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
    46	
    47	            $output = trim($result->output());
    48	            $stderr = trim($result->errorOutput());
    49	
    50	            Log::info('ClaudeCliExecutor: command completed', [
    51	                'agent_run_id' => $run->id,
    52	                'exit_code' => $result->exitCode(),
    53	                'successful' => $result->successful(),
    54	                'duration_ms' => $durationMs,
    55	                'output_length' => strlen($output),
    56	                'stderr_length' => strlen($stderr),
    57	                'output_preview' => substr($output, 0, 500),
    58	            ]);
    59	
    60	            if ($stderr) {
    61	                Log::warning('ClaudeCliExecutor: stderr output', [
    62	                    'agent_run_id' => $run->id,
    63	                    'stderr' => substr($stderr, 0, 1000),
    64	                ]);
    65	            }
    66	
    67	            if ($result->successful()) {
    68	                return new ExecutionResult(
    69	                    status: 'success',
    70	                    output: $output,
    71	                    durationMs: $durationMs,
    72	                );
    73	            }
    74	
    75	            Log::error('ClaudeCliExecutor: command failed', [
    76	                'agent_run_id' => $run->id,
    77	                'exit_code' => $result->exitCode(),
    78	                'error' => $stderr ?: 'No stderr output',
    79	                'output' => substr($output, 0, 1000),
    80	            ]);
    81	
    82	            return new ExecutionResult(
    83	                status: 'failed',
    84	                output: $output,
    85	                error: $stderr ?: 'Claude CLI exited with code '.$result->exitCode(),
    86	                durationMs: $durationMs,
    87	            );
    88	        } catch (Throwable $e) {
    89	            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
    90	
    91	            Log::error('ClaudeCliExecutor: exception during execution', [
    92	                'agent_run_id' => $run->id,
    93	                'exception' => $e->getMessage(),
    94	                'trace' => $e->getTraceAsString(),
    95	                'duration_ms' => $durationMs,
    96	            ]);
    97	
    98	            return new ExecutionResult(
    99	                status: 'failed',
   100	                error: $e->getMessage(),
   101	                durationMs: $durationMs,
   102	            );
   103	        }
   104	    }
   105	
   106	    /**
   107	     * Build the claude CLI command with all flags.
   108	     *
   109	     * @param  array<string, mixed>  $agentConfig
   110	     * @return list<string>
   111	     */
   112	    protected function buildCommand(string $renderedPrompt, array $agentConfig): array
   113	    {
   114	        $command = ['claude', '--print', '--output-format', 'text'];
   115	
   116	        $systemPrompt = $this->loadSystemPrompt($agentConfig);
   117	        if ($systemPrompt !== null) {
   118	            $command[] = '--system-prompt';
   119	            $command[] = $systemPrompt;
   120	
   121	            Log::debug('ClaudeCliExecutor: system prompt loaded', [
   122	                'length' => strlen($systemPrompt),
   123	                'source' => $agentConfig['instructions_file'] ?? 'inline',
   124	            ]);
   125	        }
   126	
   127	        $model = $agentConfig['model'] ?? null;
   128	        if ($model) {
   129	            $command[] = '--model';
   130	            $command[] = $model;
   131	        }
   132	
   133	        $maxTokens = $agentConfig['max_tokens'] ?? null;
   134	        if ($maxTokens) {
   135	            $command[] = '--max-turns';
   136	            $command[] = (string) $maxTokens;
   137	        }
   138	
   139	        $allowedTools = $agentConfig['tools'] ?? [];
   140	        foreach ($allowedTools as $tool) {
   141	            $command[] = '--allowedTools';
   142	            $command[] = $tool;
   143	        }
   144	
   145	        $disallowedTools = $agentConfig['disallowed_tools'] ?? [];
   146	        foreach ($disallowedTools as $tool) {
   147	            $command[] = '--disallowedTools';
   148	            $command[] = $tool;
   149	        }
   150	
   151	        $command[] = '--prompt';
   152	        $command[] = $renderedPrompt;
   153	
   154	        return $command;
   155	    }
   156	
   157	    /**
   158	     * Prepend conversation history to the rendered prompt.
   159	     *
   160	     * @param  list<array{role: string, content: string}>  $conversationHistory
   161	     */
   162	    protected function prependConversationHistory(string $renderedPrompt, array $conversationHistory): string
   163	    {
   164	        if (empty($conversationHistory)) {
   165	            return $renderedPrompt;
   166	        }
   167	
   168	        $historyText = app(ConversationMemory::class)->formatAsText($conversationHistory);
   169	
   170	        return $historyText."## Current Request\n\n".$renderedPrompt;
   171	    }
   172	
   173	    /**
   174	     * Load the system prompt from the instructions file if available.
   175	     *
   176	     * @param  array<string, mixed>  $agentConfig
   177	     */
   178	    protected function loadSystemPrompt(array $agentConfig): ?string
   179	    {
   180	        $instructionsFile = $agentConfig['instructions_file'] ?? null;
   181	        $projectPath = $agentConfig['project_path'] ?? null;
   182	
   183	        if ($instructionsFile && $projectPath) {
   184	            $fullPath = rtrim($projectPath, '/').'/'.$instructionsFile;
   185	
   186	            if (File::exists($fullPath)) {
   187	                return File::get($fullPath);
   188	            }
   189	
   190	            Log::warning('ClaudeCliExecutor: instructions file not found', [
   191	                'path' => $fullPath,
   192	            ]);
   193	        }
   194	
   195	        return null;
   196	    }
   197	
   198	    /**
   199	     * Redact the prompt from the command for logging (it can be very long).
   200	     *
   201	     * @param  list<string>  $command
   202	     * @return list<string>
   203	     */
   204	    protected function redactCommand(array $command): array
   205	    {
   206	        $redacted = [];
   207	        $skipNext = false;
   208	
   209	        foreach ($command as $arg) {
   210	            if ($skipNext) {
   211	                $redacted[] = '[REDACTED '.strlen($arg).' chars]';
   212	                $skipNext = false;
   213	
   214	                continue;
   215	            }
   216	
   217	            if ($arg === '--prompt' || $arg === '--system-prompt') {
   218	                $redacted[] = $arg;
   219	                $skipNext = true;
   220	
   221	                continue;
   222	            }
   223	
   224	            $redacted[] = $arg;
   225	        }
   226	
   227	        return $redacted;
   228	    }
   229	}
```

The Claude CLI executor builds a shell command like:

```
claude --print --output-format text --model claude-sonnet-4-6 --system-prompt '...' --allowedTools Read --allowedTools Bash --prompt '...'
```

Key differences from the Laravel AI executor:
- Conversation history is prepended to the prompt as text (the CLI doesn't support structured message history).
- No structured step/token tracking — it just captures stdout.
- Extensive logging with prompt redaction (line 200-228) for debuggability without leaking user data.
- 10-minute timeout (line 42).

## Step 7: The Agent and Its Tools

The `DispatchAgent` is the Laravel AI SDK agent class. It implements the `Agent`, `Conversational`, and `HasTools` interfaces:

```bash
cat -n app/Ai/Agents/DispatchAgent.php
```

```output
     1	<?php
     2	
     3	namespace App\Ai\Agents;
     4	
     5	use Laravel\Ai\Attributes\MaxSteps;
     6	use Laravel\Ai\Contracts\Agent;
     7	use Laravel\Ai\Contracts\Conversational;
     8	use Laravel\Ai\Contracts\HasTools;
     9	use Laravel\Ai\Contracts\Tool;
    10	use Laravel\Ai\Messages\AssistantMessage;
    11	use Laravel\Ai\Messages\Message;
    12	use Laravel\Ai\Promptable;
    13	use Stringable;
    14	
    15	#[MaxSteps(50)]
    16	class DispatchAgent implements Agent, Conversational, HasTools
    17	{
    18	    use Promptable;
    19	
    20	    /**
    21	     * @param  list<Tool>  $agentTools
    22	     * @param  list<Message>  $conversationMessages
    23	     */
    24	    public function __construct(
    25	        protected string $systemPrompt,
    26	        protected array $agentTools = [],
    27	        protected array $conversationMessages = [],
    28	    ) {}
    29	
    30	    public function instructions(): Stringable|string
    31	    {
    32	        return $this->systemPrompt;
    33	    }
    34	
    35	    public function tools(): iterable
    36	    {
    37	        return $this->agentTools;
    38	    }
    39	
    40	    /**
    41	     * @return list<Message>
    42	     */
    43	    public function messages(): iterable
    44	    {
    45	        return $this->conversationMessages;
    46	    }
    47	
    48	    /**
    49	     * Set conversation messages from prior history.
    50	     *
    51	     * @param  list<array{role: string, content: string}>  $history
    52	     */
    53	    public function withConversationHistory(array $history): static
    54	    {
    55	        $this->conversationMessages = array_map(
    56	            fn (array $message) => $message['role'] === 'assistant'
    57	                ? new AssistantMessage($message['content'])
    58	                : new Message('user', $message['content']),
    59	            $history,
    60	        );
    61	
    62	        return $this;
    63	    }
    64	}
```

The agent itself is thin — it's a container for the system prompt, tools, and conversation history. The `#[MaxSteps(50)]` attribute limits how many tool-use iterations the agent can perform (preventing runaway loops).

Now the `ToolRegistry` that instantiates tools for the agent:

```bash
cat -n app/Services/ToolRegistry.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\Ai\Tools\BashTool;
     6	use App\Ai\Tools\EditTool;
     7	use App\Ai\Tools\GlobTool;
     8	use App\Ai\Tools\GrepTool;
     9	use App\Ai\Tools\ReadTool;
    10	use App\Ai\Tools\WriteTool;
    11	use Laravel\Ai\Contracts\Tool;
    12	
    13	class ToolRegistry
    14	{
    15	    /**
    16	     * Map of tool names to their class implementations.
    17	     *
    18	     * @var array<string, class-string<Tool>>
    19	     */
    20	    protected static array $tools = [
    21	        'Read' => ReadTool::class,
    22	        'Edit' => EditTool::class,
    23	        'Write' => WriteTool::class,
    24	        'Bash' => BashTool::class,
    25	        'Glob' => GlobTool::class,
    26	        'Grep' => GrepTool::class,
    27	    ];
    28	
    29	    /**
    30	     * Resolve tool names to Tool instances.
    31	     *
    32	     * If $tools is empty, all available tools are resolved.
    33	     *
    34	     * @param  list<string>  $tools  Explicit list of tools to resolve (empty = all)
    35	     * @return list<Tool>
    36	     */
    37	    public function resolve(array $tools, string $workingDirectory): array
    38	    {
    39	        $toolNames = ! empty($tools) ? $tools : array_keys(static::$tools);
    40	
    41	        $resolved = [];
    42	        foreach ($toolNames as $name) {
    43	            if (isset(static::$tools[$name])) {
    44	                $resolved[] = new (static::$tools[$name])($workingDirectory);
    45	            }
    46	        }
    47	
    48	        return $resolved;
    49	    }
    50	
    51	    /**
    52	     * Get all available tool names.
    53	     *
    54	     * @return list<string>
    55	     */
    56	    public static function availableTools(): array
    57	    {
    58	        return array_keys(static::$tools);
    59	    }
    60	}
```

Six tools available, each scoped to the working directory. Let's look at a representative tool — `ReadTool` — to see the pattern:

```bash
cat -n app/Ai/Tools/ReadTool.php
```

```output
     1	<?php
     2	
     3	namespace App\Ai\Tools;
     4	
     5	use Illuminate\Contracts\JsonSchema\JsonSchema;
     6	use Illuminate\Support\Facades\File;
     7	use Laravel\Ai\Contracts\Tool;
     8	use Laravel\Ai\Tools\Request;
     9	use Stringable;
    10	
    11	class ReadTool implements Tool
    12	{
    13	    public function __construct(
    14	        protected string $workingDirectory,
    15	    ) {}
    16	
    17	    public function description(): Stringable|string
    18	    {
    19	        return 'Read the contents of a file from the project directory.';
    20	    }
    21	
    22	    public function handle(Request $request): Stringable|string
    23	    {
    24	        $path = $this->resolvePath($request->string('path'));
    25	
    26	        if ($path === null) {
    27	            return "Error: Path is outside the working directory: {$request->string('path')}";
    28	        }
    29	
    30	        if (! File::exists($path)) {
    31	            return "Error: File not found: {$request->string('path')}";
    32	        }
    33	
    34	        if (! File::isFile($path)) {
    35	            return "Error: Not a file: {$request->string('path')}";
    36	        }
    37	
    38	        return File::get($path);
    39	    }
    40	
    41	    public function schema(JsonSchema $schema): array
    42	    {
    43	        return [
    44	            'path' => $schema->string()
    45	                ->description('The relative path to the file to read.')
    46	                ->required(),
    47	        ];
    48	    }
    49	
    50	    protected function resolvePath(string $relativePath): ?string
    51	    {
    52	        $full = rtrim($this->workingDirectory, '/').'/'.ltrim($relativePath, '/');
    53	        $resolved = realpath($full);
    54	
    55	        if ($resolved !== false) {
    56	            $baseDir = rtrim(realpath($this->workingDirectory) ?: $this->workingDirectory, '/').'/';
    57	
    58	            return str_starts_with($resolved, $baseDir) ? $resolved : null;
    59	        }
    60	
    61	        // File doesn't exist yet — compare using raw working directory
    62	        $baseDir = rtrim($this->workingDirectory, '/').'/';
    63	
    64	        return str_starts_with($full, $baseDir) ? $full : null;
    65	    }
    66	}
```

Every tool follows this pattern:
- Constructor takes the working directory
- `description()` tells the LLM what the tool does
- `schema()` defines the JSON schema for the tool's parameters
- `handle()` does the work
- `resolvePath()` is the security boundary — it validates that the resolved path stays within the working directory, preventing path traversal attacks (`../../../etc/passwd`)

The `BashTool` is the most powerful — it can run arbitrary shell commands with a 120-second timeout. The `GlobTool` and `GrepTool` exclude common noise directories (`node_modules`, `vendor`, `.git`).

## Step 8: Output Handling

After the agent finishes, the `OutputHandler` routes results back to GitHub:

```bash
cat -n app/Services/OutputHandler.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\DataTransferObjects\OutputConfig;
     6	use App\Models\AgentRun;
     7	use Illuminate\Support\Facades\Log;
     8	use Illuminate\Support\Facades\Process;
     9	
    10	class OutputHandler
    11	{
    12	    /**
    13	     * Handle output routing for a completed agent run.
    14	     *
    15	     * @param  array<string, mixed>  $payload
    16	     */
    17	    public function handle(AgentRun $agentRun, OutputConfig $outputConfig, array $payload): void
    18	    {
    19	        if ($outputConfig->githubComment) {
    20	            $this->postGitHubComment($agentRun, $payload);
    21	        }
    22	    }
    23	
    24	    /**
    25	     * Post agent output as a comment on the source GitHub resource.
    26	     *
    27	     * @param  array<string, mixed>  $payload
    28	     */
    29	    protected function postGitHubComment(AgentRun $agentRun, array $payload): void
    30	    {
    31	        $resource = $this->resolveGitHubResource($payload);
    32	
    33	        if (! $resource) {
    34	            Log::warning('Could not determine GitHub resource for comment', [
    35	                'agent_run_id' => $agentRun->id,
    36	            ]);
    37	
    38	            return;
    39	        }
    40	
    41	        $repo = $payload['repository']['full_name'] ?? null;
    42	
    43	        if (! $repo) {
    44	            Log::warning('No repository found in payload for GitHub comment', [
    45	                'agent_run_id' => $agentRun->id,
    46	            ]);
    47	
    48	            return;
    49	        }
    50	
    51	        $output = $agentRun->output ?? '';
    52	
    53	        if (empty(trim($output))) {
    54	            Log::warning('Skipping GitHub comment — agent produced no output', [
    55	                'agent_run_id' => $agentRun->id,
    56	            ]);
    57	
    58	            return;
    59	        }
    60	
    61	        $result = Process::input(json_encode(['body' => $output]))
    62	            ->run([
    63	                'gh', 'api',
    64	                '-X', 'POST',
    65	                "/repos/{$repo}/{$resource['type']}/{$resource['number']}/comments",
    66	                '--input', '-',
    67	            ]);
    68	
    69	        if (! $result->successful()) {
    70	            Log::error('Failed to post GitHub comment', [
    71	                'agent_run_id' => $agentRun->id,
    72	                'error' => trim($result->errorOutput()),
    73	            ]);
    74	        }
    75	    }
    76	
    77	    /**
    78	     * Add a reaction to the triggering comment via gh CLI.
    79	     *
    80	     * @param  array<string, mixed>  $payload
    81	     */
    82	    public function addReaction(string $reaction, array $payload): void
    83	    {
    84	        $repo = $payload['repository']['full_name'] ?? null;
    85	
    86	        if (! $repo) {
    87	            Log::warning('No repository found in payload for GitHub reaction');
    88	
    89	            return;
    90	        }
    91	
    92	        // Try comment reaction first, fall back to issue/PR reaction
    93	        $commentId = $payload['comment']['id'] ?? null;
    94	
    95	        if ($commentId) {
    96	            $resourceType = $this->resolveCommentResourceType($payload);
    97	            $endpoint = "/repos/{$repo}/{$resourceType}/{$commentId}/reactions";
    98	        } else {
    99	            $resource = $this->resolveGitHubResource($payload);
   100	
   101	            if (! $resource) {
   102	                Log::warning('No reactable resource found in payload for GitHub reaction');
   103	
   104	                return;
   105	            }
   106	
   107	            $endpoint = "/repos/{$repo}/{$resource['type']}/{$resource['number']}/reactions";
   108	        }
   109	
   110	        $result = Process::run([
   111	            'gh', 'api',
   112	            '-X', 'POST',
   113	            $endpoint,
   114	            '-f', "content={$reaction}",
   115	        ]);
   116	
   117	        if (! $result->successful()) {
   118	            Log::error('Failed to add GitHub reaction', [
   119	                'reaction' => $reaction,
   120	                'error' => trim($result->errorOutput()),
   121	            ]);
   122	        }
   123	    }
   124	
   125	    /**
   126	     * Resolve the GitHub resource type and number from the webhook payload.
   127	     *
   128	     * @param  array<string, mixed>  $payload
   129	     * @return array{type: string, number: int}|null
   130	     */
   131	    public function resolveGitHubResource(array $payload): ?array
   132	    {
   133	        if (isset($payload['pull_request']['number'])) {
   134	            return [
   135	                'type' => 'issues',
   136	                'number' => $payload['pull_request']['number'],
   137	            ];
   138	        }
   139	
   140	        if (isset($payload['issue']['number'])) {
   141	            return [
   142	                'type' => 'issues',
   143	                'number' => $payload['issue']['number'],
   144	            ];
   145	        }
   146	
   147	        if (isset($payload['discussion']['number'])) {
   148	            return [
   149	                'type' => 'discussions',
   150	                'number' => $payload['discussion']['number'],
   151	            ];
   152	        }
   153	
   154	        return null;
   155	    }
   156	
   157	    /**
   158	     * Resolve the comment resource type for reactions API.
   159	     *
   160	     * @param  array<string, mixed>  $payload
   161	     */
   162	    protected function resolveCommentResourceType(array $payload): string
   163	    {
   164	        if (isset($payload['pull_request']) || str_starts_with($payload['action'] ?? '', 'pull_request')) {
   165	            return 'pulls/comments';
   166	        }
   167	
   168	        if (isset($payload['discussion'])) {
   169	            return 'discussions/comments';
   170	        }
   171	
   172	        return 'issues/comments';
   173	    }
   174	}
```

The output handler uses the `gh` CLI for all GitHub interactions — no direct API calls or HTTP clients. Two operations:

1. **Post comment** (line 29-75): Pipes JSON to `gh api -X POST /repos/{repo}/issues/{number}/comments --input -`. Note that pull requests use the `issues` endpoint for comments (line 135-137) — this is a GitHub API quirk.
2. **Add reaction** (line 82-123): Tries the comment-level reaction endpoint first (if the trigger was a comment), then falls back to the issue/PR-level endpoint.

## Step 9: Data Models

Let's see the Eloquent models that persist the pipeline state:

```bash
cat -n app/Models/WebhookLog.php && echo '---' && cat -n app/Models/AgentRun.php && echo '---' && cat -n app/Models/Project.php
```

```output
     1	<?php
     2	
     3	namespace App\Models;
     4	
     5	use Database\Factories\WebhookLogFactory;
     6	use Illuminate\Database\Eloquent\Factories\HasFactory;
     7	use Illuminate\Database\Eloquent\Model;
     8	use Illuminate\Database\Eloquent\Relations\HasMany;
     9	
    10	class WebhookLog extends Model
    11	{
    12	    /** @use HasFactory<WebhookLogFactory> */
    13	    use HasFactory;
    14	
    15	    public $timestamps = false;
    16	
    17	    protected $fillable = [
    18	        'event_type',
    19	        'repo',
    20	        'payload',
    21	        'matched_rules',
    22	        'status',
    23	        'error',
    24	        'created_at',
    25	    ];
    26	
    27	    protected function casts(): array
    28	    {
    29	        return [
    30	            'payload' => 'array',
    31	            'matched_rules' => 'array',
    32	            'created_at' => 'datetime',
    33	        ];
    34	    }
    35	
    36	    /**
    37	     * @return HasMany<AgentRun, $this>
    38	     */
    39	    public function agentRuns(): HasMany
    40	    {
    41	        return $this->hasMany(AgentRun::class);
    42	    }
    43	}
---
     1	<?php
     2	
     3	namespace App\Models;
     4	
     5	use Database\Factories\AgentRunFactory;
     6	use Illuminate\Database\Eloquent\Factories\HasFactory;
     7	use Illuminate\Database\Eloquent\Model;
     8	use Illuminate\Database\Eloquent\Relations\BelongsTo;
     9	
    10	class AgentRun extends Model
    11	{
    12	    /** @use HasFactory<AgentRunFactory> */
    13	    use HasFactory;
    14	
    15	    public $timestamps = false;
    16	
    17	    protected $fillable = [
    18	        'webhook_log_id',
    19	        'rule_id',
    20	        'attempt',
    21	        'status',
    22	        'output',
    23	        'steps',
    24	        'tokens_used',
    25	        'cost',
    26	        'duration_ms',
    27	        'error',
    28	        'created_at',
    29	    ];
    30	
    31	    protected function casts(): array
    32	    {
    33	        return [
    34	            'attempt' => 'integer',
    35	            'steps' => 'array',
    36	            'tokens_used' => 'integer',
    37	            'cost' => 'decimal:6',
    38	            'duration_ms' => 'integer',
    39	            'created_at' => 'datetime',
    40	        ];
    41	    }
    42	
    43	    /**
    44	     * @return BelongsTo<WebhookLog, $this>
    45	     */
    46	    public function webhookLog(): BelongsTo
    47	    {
    48	        return $this->belongsTo(WebhookLog::class);
    49	    }
    50	}
---
     1	<?php
     2	
     3	namespace App\Models;
     4	
     5	use Database\Factories\ProjectFactory;
     6	use Illuminate\Database\Eloquent\Factories\HasFactory;
     7	use Illuminate\Database\Eloquent\Model;
     8	use Illuminate\Database\Eloquent\Relations\BelongsTo;
     9	
    10	class Project extends Model
    11	{
    12	    /** @use HasFactory<ProjectFactory> */
    13	    use HasFactory;
    14	
    15	    protected $fillable = [
    16	        'repo',
    17	        'path',
    18	        'agent_name',
    19	        'agent_executor',
    20	        'agent_provider',
    21	        'agent_model',
    22	        'agent_instructions_file',
    23	        'agent_secrets',
    24	        'cache_config',
    25	        'github_installation_id',
    26	    ];
    27	
    28	    protected function casts(): array
    29	    {
    30	        return [
    31	            'agent_secrets' => 'array',
    32	            'cache_config' => 'boolean',
    33	        ];
    34	    }
    35	
    36	    /**
    37	     * @return BelongsTo<GitHubInstallation, $this>
    38	     */
    39	    public function githubInstallation(): BelongsTo
    40	    {
    41	        return $this->belongsTo(GitHubInstallation::class);
    42	    }
    43	}
```

The data model is straightforward:

- **WebhookLog** — One record per incoming webhook. Stores the full payload, event type, matched rule IDs, and status. Has many `AgentRun` records.
- **AgentRun** — One record per rule execution. Tracks status progression (`queued → running → success/failed/skipped`), output, token usage, cost, and duration. Belongs to a `WebhookLog`.
- **Project** — Maps a GitHub repo (`owner/repo`) to a local filesystem path. The `agent_*` columns are legacy — `dispatch.yml` is now the source of truth. Belongs to a `GitHubInstallation`.

## Step 10: GitHub App Integration

The GitHub App flow handles multi-repo webhook management. It uses GitHub's manifest flow to create an app, then syncs installations:

```bash
cat -n app/Services/GitHubAppService.php
```

```output
     1	<?php
     2	
     3	namespace App\Services;
     4	
     5	use App\Models\GitHubInstallation;
     6	use Illuminate\Http\Client\PendingRequest;
     7	use Illuminate\Support\Facades\Cache;
     8	use Illuminate\Support\Facades\Http;
     9	use Illuminate\Support\Facades\Log;
    10	use Illuminate\Support\Str;
    11	
    12	class GitHubAppService
    13	{
    14	    protected const string API_BASE = 'https://api.github.com';
    15	
    16	    public function isConfigured(): bool
    17	    {
    18	        return ! empty(config('services.github.app_id'))
    19	            && $this->resolvePrivateKey() !== null;
    20	    }
    21	
    22	    /**
    23	     * Generate a JWT for authenticating as the GitHub App.
    24	     */
    25	    public function generateJwt(): string
    26	    {
    27	        $now = time();
    28	        $payload = [
    29	            'iat' => $now - 60,
    30	            'exp' => $now + (9 * 60),
    31	            'iss' => config('services.github.app_id'),
    32	        ];
    33	
    34	        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    35	        $claims = $this->base64UrlEncode(json_encode($payload));
    36	
    37	        $pem = $this->resolvePrivateKey();
    38	
    39	        if (! $pem) {
    40	            throw new \RuntimeException('GitHub App private key not found. Set GITHUB_APP_PRIVATE_KEY or GITHUB_APP_PRIVATE_KEY_PATH.');
    41	        }
    42	
    43	        $privateKey = openssl_pkey_get_private($pem);
    44	
    45	        if (! $privateKey) {
    46	            throw new \RuntimeException('Failed to parse GitHub App private key.');
    47	        }
    48	
    49	        openssl_sign("{$header}.{$claims}", $signature, $privateKey, OPENSSL_ALGO_SHA256);
    50	
    51	        return "{$header}.{$claims}.".$this->base64UrlEncode($signature);
    52	    }
    53	
    54	    /**
    55	     * Get an installation access token (cached until near-expiry).
    56	     */
    57	    public function getInstallationToken(int $installationId): string
    58	    {
    59	        $cacheKey = "github_installation_token_{$installationId}";
    60	
    61	        return Cache::remember($cacheKey, 3500, function () use ($installationId): string {
    62	            $response = $this->appRequest()
    63	                ->post(self::API_BASE."/app/installations/{$installationId}/access_tokens");
    64	
    65	            $response->throw();
    66	
    67	            return $response->json('token');
    68	        });
    69	    }
    70	
    71	    /**
    72	     * List all installations of this GitHub App.
    73	     *
    74	     * @return array<int, array<string, mixed>>
    75	     */
    76	    public function listInstallations(): array
    77	    {
    78	        $response = $this->appRequest()
    79	            ->get(self::API_BASE.'/app/installations');
    80	
    81	        $response->throw();
    82	
    83	        return $response->json();
    84	    }
    85	
    86	    /**
    87	     * List repositories accessible to a specific installation.
    88	     *
    89	     * @return array<int, array<string, mixed>>
    90	     */
    91	    public function listRepositories(int $installationId, int $page = 1, int $perPage = 30): array
    92	    {
    93	        $token = $this->getInstallationToken($installationId);
    94	
    95	        $response = Http::withToken($token)
    96	            ->accept('application/vnd.github+json')
    97	            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
    98	            ->get(self::API_BASE.'/installation/repositories', [
    99	                'page' => $page,
   100	                'per_page' => $perPage,
   101	            ]);
   102	
   103	        $response->throw();
   104	
   105	        return $response->json();
   106	    }
   107	
   108	    /**
   109	     * Sync installations from GitHub to the database.
   110	     *
   111	     * @return array{created: int, updated: int, removed: int}
   112	     */
   113	    public function syncInstallations(): array
   114	    {
   115	        $remoteInstallations = $this->listInstallations();
   116	        $remoteIds = collect($remoteInstallations)->pluck('id');
   117	
   118	        $created = 0;
   119	        $updated = 0;
   120	
   121	        foreach ($remoteInstallations as $installation) {
   122	            $record = GitHubInstallation::updateOrCreate(
   123	                ['installation_id' => $installation['id']],
   124	                [
   125	                    'account_login' => $installation['account']['login'],
   126	                    'account_type' => $installation['account']['type'],
   127	                    'account_id' => $installation['account']['id'],
   128	                    'permissions' => $installation['permissions'] ?? [],
   129	                    'events' => $installation['events'] ?? [],
   130	                    'target_type' => $installation['target_type'] ?? 'Organization',
   131	                    'suspended_at' => $installation['suspended_at'] ?? null,
   132	                ],
   133	            );
   134	
   135	            $record->wasRecentlyCreated ? $created++ : $updated++;
   136	        }
   137	
   138	        $removed = GitHubInstallation::whereNotIn('installation_id', $remoteIds)->delete();
   139	
   140	        return compact('created', 'updated', 'removed');
   141	    }
   142	
   143	    /**
   144	     * Get the GitHub App's metadata (name, slug, description, etc).
   145	     *
   146	     * @return array<string, mixed>
   147	     */
   148	    public function getApp(): array
   149	    {
   150	        $response = $this->appRequest()
   151	            ->get(self::API_BASE.'/app');
   152	
   153	        $response->throw();
   154	
   155	        return $response->json();
   156	    }
   157	
   158	    /**
   159	     * Build the URL to install this GitHub App.
   160	     */
   161	    public function getInstallUrl(): ?string
   162	    {
   163	        if (! $this->isConfigured()) {
   164	            return null;
   165	        }
   166	
   167	        try {
   168	            $app = $this->getApp();
   169	
   170	            return $app['html_url'].'/installations/new';
   171	        } catch (\Throwable) {
   172	            return null;
   173	        }
   174	    }
   175	
   176	    /**
   177	     * Build the manifest JSON for creating a GitHub App via the manifest flow.
   178	     *
   179	     * @return array<string, mixed>
   180	     */
   181	    public function buildManifest(string $appUrl): array
   182	    {
   183	        return [
   184	            'name' => config('app.name', 'Dispatch').'-'.Str::lower(Str::random(4)),
   185	            'url' => $appUrl,
   186	            'hook_attributes' => [
   187	                'url' => rtrim($appUrl, '/').'/api/webhook',
   188	                'active' => true,
   189	            ],
   190	            'redirect_url' => rtrim($appUrl, '/').'/github/manifest/callback',
   191	            'setup_url' => rtrim($appUrl, '/').'/github/callback',
   192	            'setup_on_update' => true,
   193	            'public' => false,
   194	            'default_permissions' => [
   195	                'issues' => 'write',
   196	                'pull_requests' => 'write',
   197	                'contents' => 'read',
   198	                'metadata' => 'read',
   199	                'discussions' => 'write',
   200	            ],
   201	            'default_events' => [
   202	                'issues',
   203	                'issue_comment',
   204	                'pull_request',
   205	                'pull_request_review',
   206	                'pull_request_review_comment',
   207	                'push',
   208	                'release',
   209	                'create',
   210	                'delete',
   211	                'discussion',
   212	                'discussion_comment',
   213	                'workflow_run',
   214	            ],
   215	        ];
   216	    }
   217	
   218	    /**
   219	     * Exchange a manifest code for GitHub App credentials.
   220	     *
   221	     * @return array{id: int, slug: string, pem: string, webhook_secret: string, client_id: string, client_secret: string}
   222	     */
   223	    public function exchangeManifestCode(string $code): array
   224	    {
   225	        $response = Http::accept('application/vnd.github+json')
   226	            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
   227	            ->withBody('', 'application/json')
   228	            ->post(self::API_BASE."/app-manifests/{$code}/conversions");
   229	
   230	        $response->throw();
   231	
   232	        $data = $response->json();
   233	
   234	        return [
   235	            'id' => $data['id'],
   236	            'slug' => $data['slug'] ?? $data['name'] ?? '',
   237	            'pem' => $data['pem'],
   238	            'webhook_secret' => $data['webhook_secret'],
   239	            'client_id' => $data['client_id'] ?? '',
   240	            'client_secret' => $data['client_secret'] ?? '',
   241	            'html_url' => $data['html_url'] ?? '',
   242	            'name' => $data['name'] ?? '',
   243	        ];
   244	    }
   245	
   246	    /**
   247	     * Store GitHub App credentials in the .env file.
   248	     */
   249	    public function storeCredentials(array $credentials): void
   250	    {
   251	        $envPath = $this->envPath();
   252	        $env = file_get_contents($envPath);
   253	
   254	        Log::info('GitHubAppService::storeCredentials called', [
   255	            'app_id' => $credentials['id'],
   256	            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
   257	        ]);
   258	
   259	        $env = $this->setEnvValue($env, 'GITHUB_APP_ID', (string) $credentials['id']);
   260	        $env = $this->setEnvValue($env, 'GITHUB_APP_PRIVATE_KEY', base64_encode($credentials['pem']));
   261	        $env = $this->setEnvValue($env, 'GITHUB_WEBHOOK_SECRET', $credentials['webhook_secret']);
   262	        $env = $this->setEnvValue($env, 'GITHUB_BOT_USERNAME', $credentials['slug'] ?: $credentials['name']);
   263	
   264	        file_put_contents($envPath, $env);
   265	
   266	        // Update running config immediately
   267	        config([
   268	            'services.github.app_id' => $credentials['id'],
   269	            'services.github.app_private_key' => base64_encode($credentials['pem']),
   270	            'services.github.webhook_secret' => $credentials['webhook_secret'],
   271	            'services.github.bot_username' => $credentials['slug'] ?: $credentials['name'],
   272	        ]);
   273	    }
   274	
   275	    /**
   276	     * Delete the GitHub App on GitHub and clear local credentials.
   277	     */
   278	    public function deleteApp(): void
   279	    {
   280	        Log::warning('GitHubAppService::deleteApp called', [
   281	            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
   282	        ]);
   283	
   284	        // Delete the app on GitHub (DELETE /app, authenticated as the app via JWT)
   285	        if ($this->isConfigured()) {
   286	            $this->appRequest()->delete(self::API_BASE.'/app')->throw();
   287	        }
   288	
   289	        $this->clearCredentials();
   290	    }
   291	
   292	    /**
   293	     * Remove GitHub App credentials from .env and clear local state (without deleting on GitHub).
   294	     */
   295	    public function clearCredentials(): void
   296	    {
   297	        Log::warning('GitHubAppService::clearCredentials called', [
   298	            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
   299	        ]);
   300	
   301	        $envPath = $this->envPath();
   302	        $env = file_get_contents($envPath);
   303	
   304	        $env = $this->setEnvValue($env, 'GITHUB_APP_ID', '');
   305	        $env = $this->setEnvValue($env, 'GITHUB_APP_PRIVATE_KEY', '');
   306	        $env = $this->setEnvValue($env, 'GITHUB_WEBHOOK_SECRET', '');
   307	
   308	        file_put_contents($envPath, $env);
   309	
   310	        config([
   311	            'services.github.app_id' => null,
   312	            'services.github.app_private_key' => null,
   313	            'services.github.webhook_secret' => null,
   314	        ]);
   315	
   316	        // Remove cached installation tokens
   317	        GitHubInstallation::pluck('installation_id')->each(function (int $id): void {
   318	            Cache::forget("github_installation_token_{$id}");
   319	        });
   320	
   321	        // Remove local installation records
   322	        GitHubInstallation::query()->delete();
   323	    }
   324	
   325	    /**
   326	     * Get the URL to start the manifest flow on GitHub.
   327	     */
   328	    public function getManifestCreateUrl(?string $organization = null): string
   329	    {
   330	        if ($organization) {
   331	            return "https://github.com/organizations/{$organization}/settings/apps/new";
   332	        }
   333	
   334	        return 'https://github.com/settings/apps/new';
   335	    }
   336	
   337	    protected function setEnvValue(string $env, string $key, string $value): string
   338	    {
   339	        $escaped = str_contains($value, ' ') || str_contains($value, '#') ? "\"{$value}\"" : $value;
   340	        $newLine = "{$key}={$escaped}";
   341	
   342	        $lines = explode("\n", $env);
   343	        $found = false;
   344	
   345	        foreach ($lines as &$line) {
   346	            if (str_starts_with($line, "{$key}=")) {
   347	                $line = $newLine;
   348	                $found = true;
   349	                break;
   350	            }
   351	        }
   352	        unset($line);
   353	
   354	        if (! $found) {
   355	            $lines[] = $newLine;
   356	        }
   357	
   358	        return implode("\n", $lines);
   359	    }
   360	
   361	    /**
   362	     * Get the path to the .env file. Overridable for testing.
   363	     */
   364	    public function envPath(): string
   365	    {
   366	        return $this->envPath ?? base_path('.env');
   367	    }
   368	
   369	    /**
   370	     * Override the .env path (for testing).
   371	     */
   372	    public function useEnvPath(string $path): static
   373	    {
   374	        $this->envPath = $path;
   375	
   376	        return $this;
   377	    }
   378	
   379	    protected ?string $envPath = null;
   380	
   381	    protected function appRequest(): PendingRequest
   382	    {
   383	        return Http::withToken($this->generateJwt())
   384	            ->accept('application/vnd.github+json')
   385	            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
   386	    }
   387	
   388	    /**
   389	     * Resolve the private key PEM contents from either a base64 env var or a file path.
   390	     */
   391	    protected function resolvePrivateKey(): ?string
   392	    {
   393	        $base64 = config('services.github.app_private_key');
   394	
   395	        if (! empty($base64)) {
   396	            $decoded = base64_decode($base64, true);
   397	
   398	            return $decoded !== false ? $decoded : null;
   399	        }
   400	
   401	        $path = config('services.github.app_private_key_path', '');
   402	
   403	        if (! empty($path) && file_exists($path)) {
   404	            return file_get_contents($path);
   405	        }
   406	
   407	        return null;
   408	    }
   409	
   410	    protected function base64UrlEncode(string $data): string
   411	    {
   412	        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
   413	    }
   414	}
```

The `GitHubAppService` handles the full lifecycle of a GitHub App:

- **Manifest flow** (line 181-243): Creates a GitHub App through the web UI. You fill in a form on GitHub, get redirected back with a code, and exchange it for credentials (app ID, private key PEM, webhook secret). These get stored in `.env`.
- **JWT authentication** (line 25-52): Generates RS256 JWTs for app-level API calls. Each JWT is valid for 9 minutes.
- **Installation tokens** (line 57-69): Cached for ~58 minutes (3500s). These are scoped to a specific installation (org or user account).
- **Installation sync** (line 113-141): Pulls installations from GitHub, upserts to database, removes any that no longer exist.

## Step 11: The Web UI

The dashboard uses Livewire/Volt single-file components with Flux UI. Let's look at the Volt pages:

```bash
find resources/views -name '*.blade.php' -type f | sort
```

```output
resources/views/components/action-message.blade.php
resources/views/components/app-logo-icon.blade.php
resources/views/components/app-logo.blade.php
resources/views/components/auth-header.blade.php
resources/views/components/auth-session-status.blade.php
resources/views/components/desktop-user-menu.blade.php
resources/views/components/placeholder-pattern.blade.php
resources/views/dashboard.blade.php
resources/views/flux/icon/book-open-text.blade.php
resources/views/flux/icon/chevrons-up-down.blade.php
resources/views/flux/icon/folder-git-2.blade.php
resources/views/flux/icon/layout-grid.blade.php
resources/views/flux/navlist/group.blade.php
resources/views/layouts/app.blade.php
resources/views/layouts/app/header.blade.php
resources/views/layouts/app/sidebar.blade.php
resources/views/layouts/auth.blade.php
resources/views/layouts/auth/card.blade.php
resources/views/layouts/auth/simple.blade.php
resources/views/layouts/auth/split.blade.php
resources/views/pages/auth/confirm-password.blade.php
resources/views/pages/auth/forgot-password.blade.php
resources/views/pages/auth/login.blade.php
resources/views/pages/auth/register.blade.php
resources/views/pages/auth/reset-password.blade.php
resources/views/pages/auth/two-factor-challenge.blade.php
resources/views/pages/auth/verify-email.blade.php
resources/views/pages/projects/⚡index.blade.php
resources/views/pages/projects/⚡show.blade.php
resources/views/pages/rules/⚡index.blade.php
resources/views/pages/settings/⚡appearance.blade.php
resources/views/pages/settings/⚡delete-user-form.blade.php
resources/views/pages/settings/⚡delete-user-modal.blade.php
resources/views/pages/settings/⚡github-repos.blade.php
resources/views/pages/settings/⚡github.blade.php
resources/views/pages/settings/layout.blade.php
resources/views/pages/settings/⚡profile.blade.php
resources/views/pages/settings/⚡security.blade.php
resources/views/pages/settings/⚡two-factor-setup-modal.blade.php
resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php
resources/views/pages/webhooks/⚡index.blade.php
resources/views/pages/webhooks/⚡show.blade.php
resources/views/partials/head.blade.php
resources/views/partials/settings-heading.blade.php
resources/views/welcome.blade.php
```

The `⚡` prefix in filenames indicates Volt (single-file Livewire) components. Key pages:

- `projects/⚡index.blade.php` and `⚡show.blade.php` — Project listing and detail
- `rules/⚡index.blade.php` — Rule configuration browser
- `webhooks/⚡index.blade.php` and `⚡show.blade.php` — Webhook log viewer
- `settings/⚡github.blade.php` — GitHub App setup and manifest flow
- `settings/⚡github-repos.blade.php` — Repository picker for installations

## Step 12: Database Schema

Let's check the migrations to see the full schema:

```bash
ls -1 database/migrations/ | grep -v 'create_users\|personal_access\|create_sessions\|password_reset\|create_jobs\|create_cache\|create_failed'
```

```output
2025_08_14_170933_add_two_factor_columns_to_users_table.php
2026_03_16_061211_create_projects_table.php
2026_03_16_061212_create_rules_table.php
2026_03_16_061217_create_rule_agent_configs_table.php
2026_03_16_061218_create_rule_output_configs_table.php
2026_03_16_061219_create_rule_retry_configs_table.php
2026_03_16_061220_create_filters_table.php
2026_03_16_061221_create_webhook_logs_table.php
2026_03_16_061222_create_agent_runs_table.php
2026_03_16_062728_add_agent_config_to_projects_table.php
2026_03_16_065433_make_webhook_logs_repo_nullable.php
2026_03_16_084436_add_attempt_to_agent_runs_table.php
2026_03_16_092257_add_cache_config_to_projects_table.php
2026_03_16_121325_rename_circuit_break_to_continue_on_error_on_rules_table.php
2026_03_16_141656_create_git_hub_installations_table.php
2026_03_16_155409_add_steps_to_agent_runs_table.php
2026_03_16_163750_add_max_steps_to_rule_agent_configs_table.php
2026_03_16_191033_drop_rule_database_tables.php
```

```bash
cat -n database/migrations/2026_03_16_191033_drop_rule_database_tables.php
```

```output
     1	<?php
     2	
     3	use Illuminate\Database\Migrations\Migration;
     4	use Illuminate\Support\Facades\Schema;
     5	
     6	return new class extends Migration
     7	{
     8	    /**
     9	     * Run the migrations.
    10	     */
    11	    public function up(): void
    12	    {
    13	        // Drop in dependency order (foreign keys first)
    14	        Schema::dropIfExists('filters');
    15	        Schema::dropIfExists('rule_agent_configs');
    16	        Schema::dropIfExists('rule_output_configs');
    17	        Schema::dropIfExists('rule_retry_configs');
    18	        Schema::dropIfExists('rules');
    19	    }
    20	
    21	    /**
    22	     * Reverse the migrations.
    23	     */
    24	    public function down(): void
    25	    {
    26	        // These tables are no longer needed — rules live in dispatch.yml
    27	    }
    28	};
```

This final migration tells the story of a significant architectural decision: rules were originally stored in database tables (rules, filters, rule_agent_configs, rule_output_configs, rule_retry_configs) but were migrated to `dispatch.yml` as the source of truth. The database tables were dropped entirely — rules are now loaded from YAML at runtime and parsed into DTOs.

The remaining database tables serve as the runtime layer:
- **projects** — Maps repos to filesystem paths and GitHub installations
- **webhook_logs** — Audit trail of every webhook received
- **agent_runs** — Execution history with output, tokens, cost, duration
- **github_installations** — Synced GitHub App installation records

## Summary

The complete request lifecycle:

```
GitHub webhook POST
  → WebhookController (validate signature, detect self-loops, log)
    → ConfigLoader (parse dispatch.yml into DTOs)
      → RuleMatchingEngine (filter by event, evaluate field filters)
        → AgentDispatcher (create AgentRun records, queue jobs)
          → ProcessAgentRun job (on 'agents' queue)
            → ConversationMemory (load thread history)
            → WorktreeManager (create isolated branch if needed)
            → PromptRenderer (replace {{ event.* }} placeholders)
            → Executor (LaravelAI SDK or Claude CLI)
              → DispatchAgent + Tools (Read, Edit, Write, Bash, Glob, Grep)
            → OutputHandler (post GitHub comment, add reaction)
            → WorktreeManager (cleanup or retain worktree)
```

Each layer is a focused service with a single responsibility. The `dispatch.yml` file is the user-facing configuration surface — everything else is plumbing that turns YAML rules into AI-powered GitHub automation.
