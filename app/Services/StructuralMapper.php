<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class StructuralMapper
{
    protected const string REPOMAPPER_PATH = 'vendor/bin/repomapper';

    /**
     * Generate a structural map for a project path.
     * Returns a condensed class/function outline suitable for an AI system prompt.
     */
    public function generate(string $projectPath, int $tokenBudget = 2048): ?string
    {
        $cacheKey = $this->cacheKey($projectPath);

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $map = $this->buildMap($projectPath, $tokenBudget);

        if ($map !== null) {
            Cache::put($cacheKey, $map, now()->addHours(1));
        }

        return $map;
    }

    /**
     * Clear cached map for a project path.
     */
    public function clearCache(string $projectPath): void
    {
        Cache::forget($this->cacheKey($projectPath));
    }

    /**
     * Build the structural map by running RepoMapper.
     */
    protected function buildMap(string $projectPath, int $tokenBudget): ?string
    {
        $appPath = rtrim($projectPath, '/').'/app';

        if (! is_dir($appPath)) {
            $appPath = $projectPath;
        }

        $repoMapperScript = $this->resolveRepoMapperPath();

        if ($repoMapperScript === null) {
            Log::warning('StructuralMapper: RepoMapper not found');

            return null;
        }

        $result = Process::path($projectPath)
            ->timeout(120)
            ->run(['python', $repoMapperScript, $appPath, '--map-tokens', (string) $tokenBudget]);

        if (! $result->successful()) {
            Log::warning('StructuralMapper: RepoMapper failed', [
                'exit_code' => $result->exitCode(),
                'error' => substr($result->errorOutput(), 0, 500),
            ]);

            return null;
        }

        $output = $result->output();

        return $this->cleanOutput($output);
    }

    /**
     * Clean RepoMapper output into a usable structural map.
     */
    protected function cleanOutput(string $output): ?string
    {
        // RepoMapper wraps output in a tuple-like string — extract the map content
        if (preg_match('/\("(.+)", FileReport/s', $output, $matches)) {
            $map = $matches[1];
            // Unescape newlines
            $map = str_replace('\n', "\n", $map);
            $map = trim($map);

            return $map !== '' ? $map : null;
        }

        return null;
    }

    /**
     * Resolve the path to the RepoMapper script.
     */
    protected function resolveRepoMapperPath(): ?string
    {
        $candidates = [
            config('services.repomapper.path', ''),
            base_path('tools/RepoMapper/repomap.py'),
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function cacheKey(string $projectPath): string
    {
        return 'structural_map:'.md5(rtrim($projectPath, '/'));
    }
}
