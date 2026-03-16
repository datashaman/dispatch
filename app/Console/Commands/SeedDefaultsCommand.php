<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ConfigSyncer;
use App\Services\DefaultRulesService;
use Illuminate\Console\Command;

class SeedDefaultsCommand extends Command
{
    protected $signature = 'dispatch:seed-defaults {repo : GitHub full_name (e.g. owner/repo)}';

    protected $description = 'Seed a default dispatch.yml for a project';

    public function handle(DefaultRulesService $rulesService, ConfigSyncer $syncer): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' not found.");

            return self::FAILURE;
        }

        $created = $rulesService->seed($project);

        if (! $created) {
            $this->warn('dispatch.yml already exists for this project.');

            return self::SUCCESS;
        }

        $syncer->import($project);

        $this->info("Created dispatch.yml with default rules for '{$repo}'.");

        return self::SUCCESS;
    }
}
