<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ConfigSyncer;
use App\Services\DefaultRulesService;
use Illuminate\Console\Command;

class SeedDefaultsCommand extends Command
{
    protected $signature = 'dispatch:seed-defaults {repo : GitHub full_name (e.g. owner/repo)}';

    protected $description = 'Seed default agent rules for a project and export to dispatch.yml';

    public function handle(DefaultRulesService $rulesService, ConfigSyncer $syncer): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' not found.");

            return self::FAILURE;
        }

        $created = $rulesService->seed($project);

        if ($created === 0) {
            $this->warn('No new rules created (all defaults already exist).');

            return self::SUCCESS;
        }

        $syncer->export($project);

        $this->info("Seeded {$created} default rules and exported dispatch.yml for '{$repo}'.");

        return self::SUCCESS;
    }
}
