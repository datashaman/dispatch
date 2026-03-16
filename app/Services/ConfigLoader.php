<?php

namespace App\Services;

use App\DataTransferObjects\AgentConfig;
use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\FilterConfig;
use App\DataTransferObjects\OutputConfig;
use App\DataTransferObjects\RetryConfig;
use App\DataTransferObjects\RuleConfig;
use App\Enums\FilterOperator;
use App\Exceptions\ConfigLoadException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    /**
     * Load and validate a dispatch.yml from the given project path.
     * If the config has caching enabled, the result is cached.
     */
    public function load(string $projectPath): DispatchConfig
    {
        $cacheKey = self::cacheKey($projectPath);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof DispatchConfig) {
            return $cached;
        }

        $config = $this->loadFromDisk($projectPath);

        if ($config->cacheConfig) {
            Cache::put($cacheKey, $config);
        }

        return $config;
    }

    /**
     * Load config from disk without checking cache.
     */
    public function loadFromDisk(string $projectPath): DispatchConfig
    {
        $filePath = rtrim($projectPath, '/').'/dispatch.yml';

        if (! file_exists($filePath)) {
            throw new ConfigLoadException("Config file not found: {$filePath}");
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            throw new ConfigLoadException("Malformed YAML in {$filePath}: {$e->getMessage()}");
        }

        if (! is_array($data)) {
            throw new ConfigLoadException("Config file must contain a YAML mapping: {$filePath}");
        }

        return $this->validate($data, $filePath);
    }

    /**
     * Clear cached config for a project path.
     */
    public function clearCache(string $projectPath): void
    {
        Cache::forget(self::cacheKey($projectPath));
    }

    /**
     * Generate the cache key for a project path.
     */
    public static function cacheKey(string $projectPath): string
    {
        return 'dispatch:config:'.md5(rtrim($projectPath, '/'));
    }

    /**
     * Validate parsed YAML data and return a DispatchConfig DTO.
     *
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, string $filePath): DispatchConfig
    {
        $this->requireField($data, 'version', $filePath);
        $this->requireField($data, 'agent', $filePath);

        $agent = $data['agent'];

        if (! is_array($agent)) {
            throw new ConfigLoadException("Field 'agent' must be a mapping in {$filePath}");
        }

        $this->requireField($agent, 'name', $filePath, 'agent.');
        $this->requireField($agent, 'executor', $filePath, 'agent.');

        $this->requireField($data, 'rules', $filePath);

        if (! is_array($data['rules'])) {
            throw new ConfigLoadException("Field 'rules' must be an array in {$filePath}");
        }

        $rules = [];
        foreach ($data['rules'] as $index => $ruleData) {
            $rules[] = $this->validateRule($ruleData, $index, $filePath);
        }

        $cache = $data['cache'] ?? [];

        return new DispatchConfig(
            version: (int) $data['version'],
            agentName: $agent['name'],
            agentExecutor: $agent['executor'],
            agentInstructionsFile: $agent['instructions_file'] ?? null,
            agentProvider: $agent['provider'] ?? null,
            agentModel: $agent['model'] ?? null,
            secrets: $agent['secrets'] ?? null,
            cacheConfig: (bool) ($cache['config'] ?? false),
            rules: $rules,
        );
    }

    /**
     * Validate a single rule entry.
     */
    private function validateRule(mixed $ruleData, int $index, string $filePath): RuleConfig
    {
        if (! is_array($ruleData)) {
            throw new ConfigLoadException("Rule at index {$index} must be a mapping in {$filePath}");
        }

        $prefix = "rules[{$index}].";
        $this->requireField($ruleData, 'id', $filePath, $prefix);
        $this->requireField($ruleData, 'event', $filePath, $prefix);
        $this->requireField($ruleData, 'prompt', $filePath, $prefix);

        $filters = [];
        if (isset($ruleData['filters'])) {
            foreach ($ruleData['filters'] as $filterIndex => $filterData) {
                $filters[] = $this->validateFilter($filterData, $index, $filterIndex, $filePath);
            }
        }

        return new RuleConfig(
            id: $ruleData['id'],
            event: $ruleData['event'],
            prompt: $ruleData['prompt'],
            name: $ruleData['name'] ?? null,
            continueOnError: (bool) ($ruleData['continue_on_error'] ?? false),
            sortOrder: (int) ($ruleData['sort_order'] ?? $index),
            filters: $filters,
            agent: isset($ruleData['agent']) ? $this->parseAgentConfig($ruleData['agent']) : null,
            output: isset($ruleData['output']) ? $this->parseOutputConfig($ruleData['output']) : null,
            retry: isset($ruleData['retry']) ? $this->parseRetryConfig($ruleData['retry']) : null,
        );
    }

    /**
     * Validate a single filter entry.
     */
    private function validateFilter(mixed $filterData, int $ruleIndex, int $filterIndex, string $filePath): FilterConfig
    {
        if (! is_array($filterData)) {
            throw new ConfigLoadException("Filter at rules[{$ruleIndex}].filters[{$filterIndex}] must be a mapping in {$filePath}");
        }

        $prefix = "rules[{$ruleIndex}].filters[{$filterIndex}].";
        $this->requireField($filterData, 'field', $filePath, $prefix);
        $this->requireField($filterData, 'operator', $filePath, $prefix);
        $this->requireField($filterData, 'value', $filePath, $prefix);

        $operator = FilterOperator::tryFrom($filterData['operator']);

        if ($operator === null) {
            $allowed = implode(', ', array_column(FilterOperator::cases(), 'value'));
            $message = "Invalid filter operator '{$filterData['operator']}' at {$prefix}operator in {$filePath}. Allowed: {$allowed}";
            Log::warning($message);

            throw new ConfigLoadException($message);
        }

        return new FilterConfig(
            id: $filterData['id'] ?? null,
            field: $filterData['field'],
            operator: $operator,
            value: (string) $filterData['value'],
        );
    }

    /**
     * Parse agent config from rule data.
     *
     * @param  array<string, mixed>  $data
     */
    private function parseAgentConfig(array $data): AgentConfig
    {
        return new AgentConfig(
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
            maxSteps: isset($data['max_steps']) ? (int) $data['max_steps'] : null,
            tools: $data['tools'] ?? null,
            disallowedTools: $data['disallowed_tools'] ?? null,
            isolation: (bool) ($data['isolation'] ?? false),
        );
    }

    /**
     * Parse output config from rule data.
     *
     * @param  array<string, mixed>  $data
     */
    private function parseOutputConfig(array $data): OutputConfig
    {
        return new OutputConfig(
            log: (bool) ($data['log'] ?? true),
            githubComment: (bool) ($data['github_comment'] ?? false),
            githubReaction: $data['github_reaction'] ?? null,
        );
    }

    /**
     * Parse retry config from rule data.
     *
     * @param  array<string, mixed>  $data
     */
    private function parseRetryConfig(array $data): RetryConfig
    {
        return new RetryConfig(
            enabled: (bool) ($data['enabled'] ?? false),
            maxAttempts: (int) ($data['max_attempts'] ?? 3),
            delay: (int) ($data['delay'] ?? 60),
        );
    }

    /**
     * Require a field exists in the data array.
     *
     * @param  array<string, mixed>  $data
     */
    private function requireField(array $data, string $field, string $filePath, string $prefix = ''): void
    {
        if (! array_key_exists($field, $data)) {
            $message = "Missing required field '{$prefix}{$field}' in {$filePath}";
            Log::warning($message);

            throw new ConfigLoadException($message);
        }
    }
}
