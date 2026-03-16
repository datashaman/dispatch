<?php

namespace App\Services;

use App\DataTransferObjects\AgentConfig;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\FilterConfig;
use App\DataTransferObjects\OutputConfig;
use App\DataTransferObjects\RetryConfig;
use App\DataTransferObjects\RuleConfig;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use Symfony\Component\Yaml\Yaml;

class ConfigSyncer
{
    public function __construct(
        private ConfigLoader $configLoader,
    ) {}

    /**
     * Import dispatch.yml into the database for a project.
     */
    public function import(Project $project): DispatchConfig
    {
        // Always load from disk on import to get the latest YAML
        $config = $this->configLoader->loadFromDisk($project->path);

        // Invalidate any cached config
        $this->configLoader->clearCache($project->path);

        $this->syncProjectAgentConfig($project, $config);
        $this->syncRules($project, $config);

        return $config;
    }

    /**
     * Export database state to dispatch.yml for a project.
     */
    public function export(Project $project): DispatchConfig
    {
        $config = $this->buildConfigFromDatabase($project);

        // Invalidate any cached config since we're writing new YAML
        $this->configLoader->clearCache($project->path);

        $yaml = $this->configToYaml($config);
        $filePath = rtrim($project->path, '/').'/dispatch.yml';
        file_put_contents($filePath, $yaml);

        return $config;
    }

    /**
     * Build a DispatchConfig DTO from database state.
     */
    public function buildConfigFromDatabase(Project $project): DispatchConfig
    {
        $project->load(['rules.filters', 'rules.agentConfig', 'rules.outputConfig', 'rules.retryConfig']);

        $rules = $project->rules->map(function (Rule $rule) {
            return new RuleConfig(
                id: $rule->rule_id,
                event: $rule->event,
                prompt: $rule->prompt,
                name: $rule->name,
                continueOnError: $rule->continue_on_error,
                sortOrder: $rule->sort_order,
                filters: $rule->filters->map(fn (Filter $filter) => new FilterConfig(
                    id: $filter->filter_id,
                    field: $filter->field,
                    operator: $filter->operator,
                    value: $filter->value,
                ))->all(),
                agent: $rule->agentConfig ? new AgentConfig(
                    provider: $rule->agentConfig->provider,
                    model: $rule->agentConfig->model,
                    maxTokens: $rule->agentConfig->max_tokens,
                    tools: $rule->agentConfig->tools,
                    disallowedTools: $rule->agentConfig->disallowed_tools,
                    isolation: $rule->agentConfig->isolation,
                ) : null,
                output: $rule->outputConfig ? new OutputConfig(
                    log: $rule->outputConfig->log,
                    githubComment: $rule->outputConfig->github_comment,
                    githubReaction: $rule->outputConfig->github_reaction,
                ) : null,
                retry: $rule->retryConfig ? new RetryConfig(
                    enabled: $rule->retryConfig->enabled,
                    maxAttempts: $rule->retryConfig->max_attempts,
                    delay: $rule->retryConfig->delay,
                ) : null,
            );
        })->all();

        return new DispatchConfig(
            version: 1,
            agentName: $project->agent_name ?? '',
            agentExecutor: $project->agent_executor ?? '',
            agentInstructionsFile: $project->agent_instructions_file,
            agentProvider: $project->agent_provider,
            agentModel: $project->agent_model,
            secrets: $project->agent_secrets,
            cacheConfig: (bool) $project->cache_config,
            rules: $rules,
        );
    }

    /**
     * Convert a DispatchConfig DTO to YAML string.
     */
    public function configToYaml(DispatchConfig $config): string
    {
        $data = [
            'version' => $config->version,
            'agent' => array_filter([
                'name' => $config->agentName,
                'executor' => $config->agentExecutor,
                'instructions_file' => $config->agentInstructionsFile,
                'provider' => $config->agentProvider,
                'model' => $config->agentModel,
                'secrets' => $config->secrets,
            ], fn ($value) => $value !== null),
        ];

        if ($config->cacheConfig) {
            $data['cache'] = ['config' => true];
        }

        $data['rules'] = array_map(fn (RuleConfig $rule) => $this->ruleConfigToArray($rule), $config->rules);

        return Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Sync project-level agent config from DispatchConfig to database.
     */
    private function syncProjectAgentConfig(Project $project, DispatchConfig $config): void
    {
        $project->update([
            'agent_name' => $config->agentName,
            'agent_executor' => $config->agentExecutor,
            'agent_provider' => $config->agentProvider,
            'agent_model' => $config->agentModel,
            'agent_instructions_file' => $config->agentInstructionsFile,
            'agent_secrets' => $config->secrets,
            'cache_config' => $config->cacheConfig,
        ]);
    }

    /**
     * Sync rules from config to database, handling add/update/delete.
     */
    private function syncRules(Project $project, DispatchConfig $config): void
    {
        $configRuleIds = collect($config->rules)->pluck('id')->all();

        // Delete rules in DB but not in config
        $project->rules()
            ->whereNotIn('rule_id', $configRuleIds)
            ->delete();

        // Upsert each rule from config
        foreach ($config->rules as $ruleConfig) {
            $rule = $project->rules()->updateOrCreate(
                ['rule_id' => $ruleConfig->id],
                [
                    'name' => $ruleConfig->name ?? $ruleConfig->id,
                    'event' => $ruleConfig->event,
                    'continue_on_error' => $ruleConfig->continueOnError,
                    'prompt' => $ruleConfig->prompt,
                    'sort_order' => $ruleConfig->sortOrder,
                ],
            );

            $this->syncFilters($rule, $ruleConfig->filters);
            $this->syncAgentConfig($rule, $ruleConfig->agent);
            $this->syncOutputConfig($rule, $ruleConfig->output);
            $this->syncRetryConfig($rule, $ruleConfig->retry);
        }
    }

    /**
     * Sync filters for a rule.
     *
     * @param  list<FilterConfig>  $filters
     */
    private function syncFilters(Rule $rule, array $filters): void
    {
        // Delete existing filters and recreate
        $rule->filters()->delete();

        foreach ($filters as $index => $filterConfig) {
            $rule->filters()->create([
                'filter_id' => $filterConfig->id,
                'field' => $filterConfig->field,
                'operator' => $filterConfig->operator->value,
                'value' => $filterConfig->value,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Sync agent config for a rule.
     */
    private function syncAgentConfig(Rule $rule, ?AgentConfig $config): void
    {
        if ($config === null) {
            $rule->agentConfig()?->delete();

            return;
        }

        $rule->agentConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            [
                'provider' => $config->provider,
                'model' => $config->model,
                'max_tokens' => $config->maxTokens,
                'tools' => $config->tools,
                'disallowed_tools' => $config->disallowedTools,
                'isolation' => $config->isolation,
            ],
        );
    }

    /**
     * Sync output config for a rule.
     */
    private function syncOutputConfig(Rule $rule, ?OutputConfig $config): void
    {
        if ($config === null) {
            $rule->outputConfig()?->delete();

            return;
        }

        $rule->outputConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            [
                'log' => $config->log,
                'github_comment' => $config->githubComment,
                'github_reaction' => $config->githubReaction,
            ],
        );
    }

    /**
     * Sync retry config for a rule.
     */
    private function syncRetryConfig(Rule $rule, ?RetryConfig $config): void
    {
        if ($config === null) {
            $rule->retryConfig()?->delete();

            return;
        }

        $rule->retryConfig()->updateOrCreate(
            ['rule_id' => $rule->id],
            [
                'enabled' => $config->enabled,
                'max_attempts' => $config->maxAttempts,
                'delay' => $config->delay,
            ],
        );
    }

    /**
     * Convert a RuleConfig DTO to array for YAML output.
     *
     * @return array<string, mixed>
     */
    private function ruleConfigToArray(RuleConfig $rule): array
    {
        $data = [
            'id' => $rule->id,
            'event' => $rule->event,
            'prompt' => $rule->prompt,
        ];

        if ($rule->name !== null) {
            $data['name'] = $rule->name;
        }

        if ($rule->continueOnError) {
            $data['continue_on_error'] = true;
        }

        if ($rule->sortOrder !== 0) {
            $data['sort_order'] = $rule->sortOrder;
        }

        if (! empty($rule->filters)) {
            $data['filters'] = array_map(fn (FilterConfig $filter) => array_filter([
                'id' => $filter->id,
                'field' => $filter->field,
                'operator' => $filter->operator->value,
                'value' => $filter->value,
            ], fn ($value) => $value !== null), $rule->filters);
        }

        if ($rule->agent !== null) {
            $data['agent'] = array_filter([
                'provider' => $rule->agent->provider,
                'model' => $rule->agent->model,
                'max_tokens' => $rule->agent->maxTokens,
                'tools' => $rule->agent->tools,
                'disallowed_tools' => $rule->agent->disallowedTools,
                'isolation' => $rule->agent->isolation ?: null,
            ], fn ($value) => $value !== null);
        }

        if ($rule->output !== null) {
            $output = ['log' => $rule->output->log];
            if ($rule->output->githubComment) {
                $output['github_comment'] = true;
            }
            if ($rule->output->githubReaction !== null) {
                $output['github_reaction'] = $rule->output->githubReaction;
            }
            $data['output'] = $output;
        }

        if ($rule->retry !== null) {
            $data['retry'] = [
                'enabled' => $rule->retry->enabled,
                'max_attempts' => $rule->retry->maxAttempts,
                'delay' => $rule->retry->delay,
            ];
        }

        return $data;
    }
}
