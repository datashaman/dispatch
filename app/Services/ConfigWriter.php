<?php

namespace App\Services;

use App\DataTransferObjects\DispatchConfig;
use App\DataTransferObjects\FilterConfig;
use App\DataTransferObjects\RuleConfig;
use Symfony\Component\Yaml\Yaml;

class ConfigWriter
{
    /**
     * Write a DispatchConfig to a dispatch.yml file.
     */
    public function write(DispatchConfig $config, string $projectPath): void
    {
        $yaml = $this->toYaml($config);
        $filePath = rtrim($projectPath, '/').'/dispatch.yml';

        file_put_contents($filePath, "---\n".$yaml);
    }

    /**
     * Convert a DispatchConfig DTO to YAML string.
     */
    public function toYaml(DispatchConfig $config): string
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

        $data['rules'] = array_map(fn (RuleConfig $rule) => $this->ruleToArray($rule), $config->rules);

        return Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Convert a RuleConfig DTO to array for YAML output.
     *
     * @return array<string, mixed>
     */
    protected function ruleToArray(RuleConfig $rule): array
    {
        $data = [
            'id' => $rule->id,
            'event' => $rule->event,
            'name' => $rule->name,
            'prompt' => $rule->prompt,
        ];

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
                'max_steps' => $rule->agent->maxSteps,
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
