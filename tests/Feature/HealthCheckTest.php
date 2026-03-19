<?php

use App\Models\Project;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

it('passes all checks when everything is healthy', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_health_');
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/dispatch.yml', "version: 1\nagent:\n  name: test\n  executor: laravel-ai\nrules:\n  - id: r1\n    event: issues.labeled\n    prompt: test\n");

    Project::factory()->create(['repo' => 'owner/healthy', 'path' => $tempDir]);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('PASS');
    expect($output)->toContain('All health checks passed');

    @unlink($tempDir.'/dispatch.yml');
    @rmdir($tempDir);
});

it('fails when github app is not configured', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('FAIL');
    expect($output)->toContain('Not configured');
});

it('fails when github app cannot connect', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andThrow(new RuntimeException('Connection refused'));
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('FAIL');
    expect($output)->toContain('cannot connect');
});

it('fails when redis is not reachable', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andThrow(new RuntimeException('Connection refused'));

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('FAIL');
    expect($output)->toContain('Cannot connect to Redis');
});

it('fails when a project path does not exist', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    Project::factory()->create(['repo' => 'owner/missing', 'path' => '/nonexistent/path']);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('FAIL');
    expect($output)->toContain('Path does not exist');
});

it('fails when dispatch.yml is missing for a project', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_health_');
    mkdir($tempDir, 0755, true);

    Project::factory()->create(['repo' => 'owner/noconfig', 'path' => $tempDir]);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('dispatch.yml error');

    @rmdir($tempDir);
});

it('fails when dispatch.yml is invalid for a project', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_health_');
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/dispatch.yml', "version: 1\n");

    Project::factory()->create(['repo' => 'owner/invalid', 'path' => $tempDir]);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('dispatch.yml error');

    @unlink($tempDir.'/dispatch.yml');
    @rmdir($tempDir);
});

it('passes with no projects registered', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('No projects registered');
    expect($output)->toContain('All health checks passed');
});

it('reports pass and fail for mixed project states', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getApp')->andReturn(['name' => 'dispatch-test']);
    app()->instance(GitHubAppService::class, $mock);

    Redis::shouldReceive('ping')->once()->andReturn(true);

    $tempDir = sys_get_temp_dir().'/'.uniqid('dispatch_health_');
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/dispatch.yml', "version: 1\nagent:\n  name: test\n  executor: laravel-ai\nrules:\n  - id: r1\n    event: issues.labeled\n    prompt: test\n");

    Project::factory()->create(['repo' => 'owner/good', 'path' => $tempDir]);
    Project::factory()->create(['repo' => 'owner/bad', 'path' => '/nonexistent']);

    $exitCode = Artisan::call('dispatch:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('PASS');
    expect($output)->toContain('FAIL');
    expect($output)->toContain('Some health checks failed');

    @unlink($tempDir.'/dispatch.yml');
    @rmdir($tempDir);
});
