<?php

use App\DataTransferObjects\DispatchConfig;
use App\Models\Project;
use App\Models\User;
use App\Services\ConfigSyncer;
use Illuminate\Support\Facades\File;
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
        File::deleteDirectory($path);
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

test('can edit a project', function () {
    $path = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($path);

    try {
        $project = Project::factory()->create([
            'repo' => 'owner/original',
            'path' => $path,
        ]);

        Volt::test('pages::projects.index')
            ->call('editProject', $project->id)
            ->assertSet('editRepo', 'owner/original')
            ->assertSet('editPath', $path)
            ->set('editRepo', 'owner/updated')
            ->set('editAgentName', 'sparky')
            ->set('editAgentExecutor', 'laravel-ai')
            ->set('editAgentProvider', 'anthropic')
            ->set('editAgentModel', 'claude-sonnet-4-6')
            ->set('editAgentInstructionsFile', 'SPARKY.md')
            ->set('editCacheConfig', true)
            ->call('updateProject');

        $project->refresh();
        expect($project->repo)->toBe('owner/updated');
        expect($project->agent_name)->toBe('sparky');
        expect($project->agent_executor)->toBe('laravel-ai');
        expect($project->agent_provider)->toBe('anthropic');
        expect($project->agent_model)->toBe('claude-sonnet-4-6');
        expect($project->agent_instructions_file)->toBe('SPARKY.md');
        expect($project->cache_config)->toBeTrue();
    } finally {
        File::deleteDirectory($path);
    }
});

test('validates path exists on disk when editing', function () {
    $path = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($path);

    try {
        $project = Project::factory()->create([
            'repo' => 'owner/repo',
            'path' => $path,
        ]);

        Volt::test('pages::projects.index')
            ->call('editProject', $project->id)
            ->set('editPath', '/nonexistent/path/that/does/not/exist')
            ->call('updateProject')
            ->assertHasErrors('editPath');
    } finally {
        File::deleteDirectory($path);
    }
});

test('validates repo uniqueness when editing', function () {
    Project::factory()->create(['repo' => 'owner/other']);
    $project = Project::factory()->create(['repo' => 'owner/mine']);

    $path = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($path);

    try {
        Volt::test('pages::projects.index')
            ->call('editProject', $project->id)
            ->set('editRepo', 'owner/other')
            ->set('editPath', $path)
            ->call('updateProject')
            ->assertHasErrors('editRepo');
    } finally {
        File::deleteDirectory($path);
    }
});

test('project show page displays project details', function () {
    $project = Project::factory()->create([
        'repo' => 'owner/my-repo',
        'path' => '/tmp/my-repo',
        'agent_name' => 'sparky',
        'agent_executor' => 'laravel-ai',
        'agent_provider' => 'anthropic',
        'agent_model' => 'claude-sonnet-4-6',
        'agent_instructions_file' => 'SPARKY.md',
        'cache_config' => true,
    ]);

    Volt::test('pages::projects.show', ['project' => $project->id])
        ->assertSee('owner/my-repo')
        ->assertSee('/tmp/my-repo')
        ->assertSee('sparky')
        ->assertSee('laravel-ai')
        ->assertSee('anthropic')
        ->assertSee('claude-sonnet-4-6')
        ->assertSee('SPARKY.md')
        ->assertSee('Enabled');
});

test('project show page handles missing project', function () {
    Volt::test('pages::projects.show', ['project' => 99999])
        ->assertSee('Project not found');
});
