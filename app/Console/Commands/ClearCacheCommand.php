<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ConfigLoader;
use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'dispatch:clear-cache {repo? : The repo to clear cache for (all if omitted)}';

    protected $description = 'Clear cached dispatch config for a project or all projects';

    public function handle(ConfigLoader $configLoader): int
    {
        $repo = $this->argument('repo');

        if ($repo) {
            $project = Project::where('repo', $repo)->first();

            if (! $project) {
                $this->error("Project not found: {$repo}");

                return self::FAILURE;
            }

            $configLoader->clearCache($project->path);
            $this->info("Cleared config cache for {$repo}.");

            return self::SUCCESS;
        }

        $projects = Project::all();

        if ($projects->isEmpty()) {
            $this->info('No projects registered. Nothing to clear.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $configLoader->clearCache($project->path);
        }

        $this->info("Cleared config cache for {$projects->count()} project(s).");

        return self::SUCCESS;
    }
}
