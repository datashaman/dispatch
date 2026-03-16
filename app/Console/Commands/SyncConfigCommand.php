<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncConfigCommand extends Command
{
    protected $signature = 'dispatch:sync {repo} {--direction=import : Direction of sync (import or export)}';

    protected $description = 'Sync config between dispatch.yml and database (convenience alias)';

    public function handle(): int
    {
        $repo = $this->argument('repo');
        $direction = $this->option('direction');

        if (! in_array($direction, ['import', 'export'])) {
            $this->error("Invalid direction '{$direction}'. Use 'import' or 'export'.");

            return self::FAILURE;
        }

        return $this->call("dispatch:{$direction}", ['repo' => $repo]);
    }
}
