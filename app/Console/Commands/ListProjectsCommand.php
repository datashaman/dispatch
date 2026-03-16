<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;

class ListProjectsCommand extends Command
{
    protected $signature = 'dispatch:list-projects';

    protected $description = 'List all registered projects';

    public function handle(): int
    {
        $projects = Project::all(['repo', 'path']);

        if ($projects->isEmpty()) {
            $this->info('No projects registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Repo', 'Path'],
            $projects->toArray()
        );

        return self::SUCCESS;
    }
}
