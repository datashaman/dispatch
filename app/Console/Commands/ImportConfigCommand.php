<?php

namespace App\Console\Commands;

use App\Exceptions\ConfigLoadException;
use App\Models\Project;
use App\Services\ConfigSyncer;
use Illuminate\Console\Command;

class ImportConfigCommand extends Command
{
    protected $signature = 'dispatch:import {repo}';

    protected $description = 'Import dispatch.yml into the database for a project';

    public function handle(ConfigSyncer $syncer): int
    {
        $repo = $this->argument('repo');

        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            $this->error("Project '{$repo}' not found.");

            return self::FAILURE;
        }

        try {
            $config = $syncer->import($project);
        } catch (ConfigLoadException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ruleCount = count($config->rules);
        $this->info("Imported {$ruleCount} rule(s) from dispatch.yml for '{$repo}'.");

        return self::SUCCESS;
    }
}
