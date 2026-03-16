<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ConfigSyncer;
use Illuminate\Console\Command;

class ExportConfigCommand extends Command
{
    protected $signature = 'dispatch:export {repo}';

    protected $description = 'Export database state to dispatch.yml for a project';

    public function handle(ConfigSyncer $syncer): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' not found.");

            return self::FAILURE;
        }

        $config = $syncer->export($project);

        $ruleCount = count($config->rules);
        $this->info("Exported {$ruleCount} rule(s) to dispatch.yml for '{$repo}'.");

        return self::SUCCESS;
    }
}
