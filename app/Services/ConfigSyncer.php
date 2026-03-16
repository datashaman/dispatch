<?php

namespace App\Services;

use App\DataTransferObjects\DispatchConfig;
use App\Models\Project;

class ConfigSyncer
{
    public function __construct(
        private ConfigLoader $configLoader,
    ) {}

    /**
     * Sync project-level agent config from dispatch.yml to the database.
     * The file is the source of truth — this updates the Project model's
     * agent defaults (name, executor, provider, model, etc).
     */
    public function import(Project $project): DispatchConfig
    {
        $config = $this->configLoader->loadFromDisk($project->path);

        $this->configLoader->clearCache($project->path);

        $this->syncProjectAgentConfig($project, $config);

        return $config;
    }

    /**
     * Sync project-level agent config from DispatchConfig to database.
     */
    private function syncProjectAgentConfig(Project $project, DispatchConfig $config): void
    {
        $project->update([
            'agent_name' => $config->agentName ?: null,
            'agent_executor' => $config->agentExecutor ?: null,
            'agent_provider' => $config->agentProvider,
            'agent_model' => $config->agentModel,
            'agent_instructions_file' => $config->agentInstructionsFile,
            'agent_secrets' => $config->secrets,
            'cache_config' => $config->cacheConfig,
        ]);
    }
}
