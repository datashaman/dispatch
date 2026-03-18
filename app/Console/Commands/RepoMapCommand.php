<?php

namespace App\Console\Commands;

use App\Services\StructuralMapper;
use Illuminate\Console\Command;

class RepoMapCommand extends Command
{
    protected $signature = 'dispatch:map
        {path? : Project path to map (defaults to current directory)}
        {--tokens=2048 : Token budget for the map}
        {--fresh : Clear cache and regenerate}';

    protected $description = 'Generate a structural map of a project codebase';

    public function handle(StructuralMapper $mapper): int
    {
        $path = $this->argument('path') ?? base_path();
        $tokens = (int) $this->option('tokens');

        if ($this->option('fresh')) {
            $mapper->clearCache($path);
            $this->components->info('Cache cleared.');
        }

        $this->components->info("Generating structural map for: {$path}");
        $this->components->info("Token budget: {$tokens}");

        $map = $mapper->generate($path, $tokens);

        if ($map === null) {
            $this->components->error('Failed to generate map. Is RepoMapper installed?');
            $this->components->bulletList([
                'Clone: git clone https://github.com/pdavis68/RepoMapper.git tools/RepoMapper',
                'Install: cd tools/RepoMapper && pip install -r requirements.txt',
            ]);

            return self::FAILURE;
        }

        $this->line($map);

        return self::SUCCESS;
    }
}
