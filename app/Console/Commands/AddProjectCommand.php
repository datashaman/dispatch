<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DefaultRulesService;
use Illuminate\Console\Command;

class AddProjectCommand extends Command
{
    protected $signature = 'dispatch:add-project {repo : GitHub full_name (e.g. owner/repo)} {path : Local filesystem path}';

    protected $description = 'Register a local project with the webhook server';

    public function handle(): int
    {
        $repo = $this->argument('repo');
        $path = $this->argument('path');

        if (Project::where('repo', $repo)->exists()) {
            $this->error("Project '{$repo}' already exists.");

            return self::FAILURE;
        }

        if (! is_dir($path)) {
            $this->error("Path '{$path}' does not exist or is not a directory.");

            return self::FAILURE;
        }

        $project = Project::create([
            'repo' => $repo,
            'path' => $path,
        ]);

        $created = app(DefaultRulesService::class)->seed($project);

        $this->info("Project '{$repo}' added with {$created} default rules.");

        return self::SUCCESS;
    }
}
