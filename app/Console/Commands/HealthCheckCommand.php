<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ConfigLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class HealthCheckCommand extends Command
{
    protected $signature = 'dispatch:health';

    protected $description = 'Check system dependencies and project configurations';

    public function handle(ConfigLoader $configLoader): int
    {
        $this->info('Running health checks...');
        $this->newLine();

        $allPassed = true;

        $allPassed = $this->checkGhCli() && $allPassed;
        $allPassed = $this->checkRedis() && $allPassed;
        $allPassed = $this->checkProjects($configLoader) && $allPassed;

        $this->newLine();

        if ($allPassed) {
            $this->info('All health checks passed.');
        } else {
            $this->error('Some health checks failed. See details above.');
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function checkGhCli(): bool
    {
        $result = Process::run('gh auth status');

        if ($result->successful()) {
            $this->printCheck(true, 'GitHub CLI', 'Installed and authenticated');

            return true;
        }

        $output = trim($result->errorOutput() ?: $result->output());

        if (str_contains($output, 'not logged in') || str_contains($output, 'not authenticated')) {
            $this->printCheck(false, 'GitHub CLI', 'Not authenticated. Run `gh auth login` to authenticate.');
        } else {
            $this->printCheck(false, 'GitHub CLI', 'Not installed or not working. Install from https://cli.github.com/');
        }

        return false;
    }

    private function checkRedis(): bool
    {
        try {
            Redis::ping();
            $this->printCheck(true, 'Redis', 'Connected');

            return true;
        } catch (\Throwable $e) {
            $this->printCheck(false, 'Redis', 'Cannot connect to Redis. Ensure Redis is running. Error: '.$e->getMessage());

            return false;
        }
    }

    private function checkProjects(ConfigLoader $configLoader): bool
    {
        $projects = Project::all();

        if ($projects->isEmpty()) {
            $this->printCheck(true, 'Projects', 'No projects registered (nothing to check)');

            return true;
        }

        $allPassed = true;

        foreach ($projects as $project) {
            $pathExists = is_dir($project->path);

            if (! $pathExists) {
                $this->printCheck(false, "Project [{$project->repo}]", "Path does not exist: {$project->path}");
                $allPassed = false;

                continue;
            }

            $this->printCheck(true, "Project [{$project->repo}]", "Path exists: {$project->path}");

            try {
                $configLoader->load($project->path);
                $this->printCheck(true, "Project [{$project->repo}]", 'dispatch.yml is valid');
            } catch (\Throwable $e) {
                $this->printCheck(false, "Project [{$project->repo}]", 'dispatch.yml error: '.$e->getMessage());
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    private function printCheck(bool $passed, string $name, string $message): void
    {
        $status = $passed ? 'PASS' : 'FAIL';
        $this->line("  [{$status}] {$name}: {$message}");
    }
}
