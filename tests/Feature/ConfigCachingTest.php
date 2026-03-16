<?php

use App\DataTransferObjects\DispatchConfig;
use App\Models\Project;
use App\Services\ConfigLoader;
use App\Services\ConfigSyncer;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->loader = new ConfigLoader;
    $this->tempDir = sys_get_temp_dir().'/dispatch-cache-test-'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    $configFile = $this->tempDir.'/dispatch.yml';
    if (file_exists($configFile)) {
        unlink($configFile);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

function writeCacheConfig(string $dir, string $yaml): void
{
    file_put_contents($dir.'/dispatch.yml', $yaml);
}

function cachingYaml(bool $cacheEnabled = true): string
{
    $cacheValue = $cacheEnabled ? 'true' : 'false';

    return <<<YAML
version: 1
agent:
  name: "sparky"
  executor: "laravel-ai"

cache:
  config: {$cacheValue}

rules:
  - id: "analyze"
    event: "issues.labeled"
    prompt: "Analyze the issue."
YAML;
}

it('caches config when cache.config is true', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $config = $this->loader->load($this->tempDir);

    expect($config)->toBeInstanceOf(DispatchConfig::class);
    expect($config->cacheConfig)->toBeTrue();

    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->toBeInstanceOf(DispatchConfig::class);
});

it('does not cache config when cache.config is false', function () {
    writeCacheConfig($this->tempDir, cachingYaml(false));

    $config = $this->loader->load($this->tempDir);

    expect($config->cacheConfig)->toBeFalse();

    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->toBeNull();
});

it('returns cached config on subsequent loads', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $config1 = $this->loader->load($this->tempDir);

    // Modify the file on disk — cached version should still be returned
    writeCacheConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "modified-agent"
  executor: "laravel-ai"

cache:
  config: true

rules:
  - id: "modified"
    event: "push"
    prompt: "Modified prompt."
YAML);

    $config2 = $this->loader->load($this->tempDir);

    expect($config2->agentName)->toBe('sparky');
    expect($config2->rules[0]->id)->toBe('analyze');
});

it('clears cache for a specific project path', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $this->loader->load($this->tempDir);

    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->not->toBeNull();

    $this->loader->clearCache($this->tempDir);

    expect(Cache::get($cacheKey))->toBeNull();
});

it('loads fresh config after cache is cleared', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $this->loader->load($this->tempDir);

    // Modify the file
    writeCacheConfig($this->tempDir, <<<'YAML'
version: 1
agent:
  name: "updated-agent"
  executor: "laravel-ai"

cache:
  config: true

rules:
  - id: "updated"
    event: "push"
    prompt: "Updated prompt."
YAML);

    $this->loader->clearCache($this->tempDir);
    $config = $this->loader->load($this->tempDir);

    expect($config->agentName)->toBe('updated-agent');
    expect($config->rules[0]->id)->toBe('updated');
});

it('generates consistent cache keys', function () {
    $key1 = ConfigLoader::cacheKey('/some/path');
    $key2 = ConfigLoader::cacheKey('/some/path/');
    $key3 = ConfigLoader::cacheKey('/some/other/path');

    expect($key1)->toBe($key2);
    expect($key1)->not->toBe($key3);
});

it('invalidates cache on import', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $project = Project::factory()->create([
        'repo' => 'test/cache-import',
        'path' => $this->tempDir,
    ]);

    // Load config to populate cache
    $this->loader->load($this->tempDir);
    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->not->toBeNull();

    // Import should clear cache
    $syncer = app(ConfigSyncer::class);
    $syncer->import($project);

    expect(Cache::get($cacheKey))->toBeNull();
});

it('dispatch:clear-cache clears cache for a specific repo', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $project = Project::factory()->create([
        'repo' => 'test/clear-cache-repo',
        'path' => $this->tempDir,
    ]);

    $this->loader->load($this->tempDir);
    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->not->toBeNull();

    $this->artisan('dispatch:clear-cache', ['repo' => 'test/clear-cache-repo'])
        ->expectsOutputToContain('Cleared config cache for test/clear-cache-repo')
        ->assertExitCode(0);

    expect(Cache::get($cacheKey))->toBeNull();
});

it('dispatch:clear-cache clears cache for all repos', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    Project::factory()->create([
        'repo' => 'test/clear-all-1',
        'path' => $this->tempDir,
    ]);

    $this->loader->load($this->tempDir);
    $cacheKey = ConfigLoader::cacheKey($this->tempDir);
    expect(Cache::get($cacheKey))->not->toBeNull();

    $this->artisan('dispatch:clear-cache')
        ->expectsOutputToContain('Cleared config cache for 1 project(s)')
        ->assertExitCode(0);

    expect(Cache::get($cacheKey))->toBeNull();
});

it('dispatch:clear-cache fails for unknown repo', function () {
    $this->artisan('dispatch:clear-cache', ['repo' => 'nonexistent/repo'])
        ->expectsOutputToContain('Project not found: nonexistent/repo')
        ->assertExitCode(1);
});

it('dispatch:clear-cache handles no projects gracefully', function () {
    $this->artisan('dispatch:clear-cache')
        ->expectsOutputToContain('No projects registered')
        ->assertExitCode(0);
});

it('syncs cache_config to database on import', function () {
    writeCacheConfig($this->tempDir, cachingYaml(true));

    $project = Project::factory()->create([
        'repo' => 'test/cache-sync',
        'path' => $this->tempDir,
        'cache_config' => false,
    ]);

    $syncer = app(ConfigSyncer::class);
    $syncer->import($project);

    $project->refresh();
    expect($project->cache_config)->toBeTrue();
});
