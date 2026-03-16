<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class RemoveProjectCommand extends Command
{
    protected $signature = 'dispatch:remove-project {repo : GitHub full_name (e.g. owner/repo)}';

    protected $description = 'Remove a registered project from the webhook server';

    public function handle(): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' does not exist.");

            return self::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to remove project '{$repo}'?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $project->delete();

        $this->info("Project '{$repo}' removed successfully.");

        return self::SUCCESS;
    }
}
