<?php

use App\DataTransferObjects\DispatchConfig;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\ConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

$fakeKeyPath = null;

beforeEach(function () use (&$fakeKeyPath) {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Cache::flush();

    if ($fakeKeyPath === null || ! file_exists($fakeKeyPath)) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $fakeKeyPath = tempnam(sys_get_temp_dir(), 'gh_key_');
        file_put_contents($fakeKeyPath, $pem);
    }

    $this->fakeKeyPath = $fakeKeyPath;
});

afterAll(function () use (&$fakeKeyPath) {
    if ($fakeKeyPath !== null && file_exists($fakeKeyPath)) {
        unlink($fakeKeyPath);
        $fakeKeyPath = null;
    }
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
        ->assertSee('No repos connected');
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

test('repo picker search filters across all pages', function () {
    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key' => null]);
    config(['services.github.app_private_key_path' => $this->fakeKeyPath]);
    $installation = GitHubInstallation::factory()->create();

    // Build page 1 with 100 non-matching repos to trigger pagination
    $page1Repos = array_map(fn ($i) => [
        'id' => $i, 'full_name' => "org/filler-{$i}", 'name' => "filler-{$i}",
        'description' => null, 'private' => false, 'language' => 'PHP',
    ], range(1, 100));

    // Page 2 has the target repo
    $page2Repos = [
        ['id' => 101, 'full_name' => 'org/summry', 'name' => 'summry', 'description' => null, 'private' => true, 'language' => 'PHP'],
        ['id' => 102, 'full_name' => 'org/zebra', 'name' => 'zebra', 'description' => null, 'private' => false, 'language' => null],
    ];

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => function ($request) use ($page1Repos, $page2Repos) {
            $page = (int) ($request->data()['page'] ?? $request['page'] ?? 1);

            return Http::response([
                'total_count' => 102,
                'repositories' => $page === 1 ? $page1Repos : $page2Repos,
            ]);
        },
    ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker', $installation->installation_id)
        ->assertSee('org/summry')
        ->assertSee('org/zebra')
        ->set('repoPickerSearch', 'summ')
        ->assertSee('org/summry')
        ->assertDontSee('org/zebra')
        ->assertDontSee('org/filler-1');
});

test('repo picker sort and direction changes order', function () {
    config(['services.github.app_id' => '12345']);
    config(['services.github.app_private_key' => null]);
    config(['services.github.app_private_key_path' => $this->fakeKeyPath]);
    $installation = GitHubInstallation::factory()->create();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'fake-token']),
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'org/beta', 'name' => 'beta', 'description' => null, 'private' => false, 'language' => null, 'created_at' => '2026-01-01T00:00:00Z'],
                ['id' => 2, 'full_name' => 'org/alpha', 'name' => 'alpha', 'description' => null, 'private' => false, 'language' => null, 'created_at' => '2025-01-01T00:00:00Z'],
            ],
        ]),
    ]);

    $component = Volt::test('pages::projects.index')
        ->call('openRepoPicker', $installation->installation_id);

    // Default: name asc — alpha before beta
    $html = $component->html();
    $alphaPos = strpos($html, 'org/alpha');
    $betaPos = strpos($html, 'org/beta');
    expect($alphaPos)->not->toBeFalse();
    expect($betaPos)->not->toBeFalse();
    expect($alphaPos)->toBeLessThan($betaPos);

    // Direction desc — beta before alpha
    $component->set('repoPickerDirection', 'desc');
    $html = $component->html();
    $betaPos = strpos($html, 'org/beta');
    $alphaPos = strpos($html, 'org/alpha');
    expect($betaPos)->not->toBeFalse();
    expect($alphaPos)->not->toBeFalse();
    expect($betaPos)->toBeLessThan($alphaPos);

    // Sort by created_at asc — alpha (2025) before beta (2026)
    $component->set('repoPickerSort', 'created_at');
    $component->set('repoPickerDirection', 'asc');
    $html = $component->html();
    $alphaPos = strpos($html, 'org/alpha');
    $betaPos = strpos($html, 'org/beta');
    expect($alphaPos)->not->toBeFalse();
    expect($betaPos)->not->toBeFalse();
    expect($alphaPos)->toBeLessThan($betaPos);
});
