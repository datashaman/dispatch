<?php

use App\DataTransferObjects\DispatchConfig;
use App\Models\Project;
use App\Models\User;
use App\Services\ConfigSyncer;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('projects page is accessible to authenticated users', function () {
    $this->get(route('projects.index'))
        ->assertStatus(200);
});

test('projects page requires authentication', function () {
    auth()->logout();

    $this->get(route('projects.index'))
        ->assertRedirect(route('login'));
});

test('lists all registered projects', function () {
    Project::factory()->create(['repo' => 'owner/repo-a', 'path' => '/tmp/repo-a']);
    Project::factory()->create(['repo' => 'owner/repo-b', 'path' => '/tmp/repo-b']);

    Volt::test('pages::projects.index')
        ->assertSee('owner/repo-a')
        ->assertSee('owner/repo-b')
        ->assertSee('/tmp/repo-a')
        ->assertSee('/tmp/repo-b');
});

test('shows empty state when no projects exist', function () {
    Volt::test('pages::projects.index')
        ->assertSee('No projects registered');
});

test('can add a new project', function () {
    $path = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($path);

    try {
        Volt::test('pages::projects.index')
            ->set('newRepo', 'owner/new-repo')
            ->set('newPath', $path)
            ->call('addProject');

        expect(Project::where('repo', 'owner/new-repo')->exists())->toBeTrue();
        expect(Project::where('repo', 'owner/new-repo')->first()->path)->toBe($path);
    } finally {
        rmdir($path);
    }
});

test('validates repo is unique when adding', function () {
    Project::factory()->create(['repo' => 'owner/existing']);

    Volt::test('pages::projects.index')
        ->set('newRepo', 'owner/existing')
        ->set('newPath', '/tmp')
        ->call('addProject')
        ->assertHasErrors('newRepo');
});

test('validates path exists on disk when adding', function () {
    Volt::test('pages::projects.index')
        ->set('newRepo', 'owner/new-repo')
        ->set('newPath', '/nonexistent/path/that/does/not/exist')
        ->call('addProject')
        ->assertHasErrors('newPath');

    expect(Project::where('repo', 'owner/new-repo')->exists())->toBeFalse();
});

test('validates required fields when adding', function () {
    Volt::test('pages::projects.index')
        ->set('newRepo', '')
        ->set('newPath', '')
        ->call('addProject')
        ->assertHasErrors(['newRepo', 'newPath']);
});

test('can remove a project with confirmation', function () {
    $project = Project::factory()->create(['repo' => 'owner/to-delete']);

    Volt::test('pages::projects.index')
        ->call('removeProject', $project->id);

    expect(Project::find($project->id))->toBeNull();
});

test('can import config for a project', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $dummyConfig = new DispatchConfig(version: 1, agentName: 'test', agentExecutor: 'laravel-ai');

    $mock = Mockery::mock(ConfigSyncer::class);
    $mock->shouldReceive('import')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === $project->id))
        ->andReturn($dummyConfig);
    app()->instance(ConfigSyncer::class, $mock);

    Volt::test('pages::projects.index')
        ->call('importConfig', $project->id);
});

test('can export config for a project', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);
    $dummyConfig = new DispatchConfig(version: 1, agentName: 'test', agentExecutor: 'laravel-ai');

    $mock = Mockery::mock(ConfigSyncer::class);
    $mock->shouldReceive('export')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === $project->id))
        ->andReturn($dummyConfig);
    app()->instance(ConfigSyncer::class, $mock);

    Volt::test('pages::projects.index')
        ->call('exportConfig', $project->id);
});

test('shows error when import fails', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);

    $mock = Mockery::mock(ConfigSyncer::class);
    $mock->shouldReceive('import')
        ->once()
        ->andThrow(new RuntimeException('YAML parse error'));
    app()->instance(ConfigSyncer::class, $mock);

    Volt::test('pages::projects.index')
        ->call('importConfig', $project->id)
        ->assertSet('errorMessage', 'Import failed: YAML parse error');
});

test('shows error when export fails', function () {
    $project = Project::factory()->create(['repo' => 'owner/repo']);

    $mock = Mockery::mock(ConfigSyncer::class);
    $mock->shouldReceive('export')
        ->once()
        ->andThrow(new RuntimeException('Write permission denied'));
    app()->instance(ConfigSyncer::class, $mock);

    Volt::test('pages::projects.index')
        ->call('exportConfig', $project->id)
        ->assertSet('errorMessage', 'Export failed: Write permission denied');
});
