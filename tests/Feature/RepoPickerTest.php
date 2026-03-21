<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
        'account_login' => 'owner',
    ]);

    $this->mockAppService = Mockery::mock(GitHubAppService::class);
    $this->mockAppService->shouldReceive('isConfigured')->andReturn(true);
    $this->mockAppService->shouldReceive('getInstallationToken')->andReturn('fake-token');
    app()->instance(GitHubAppService::class, $this->mockAppService);
});

test('connect repos button appears when github app is configured', function () {
    Volt::test('pages::projects.index')
        ->assertSee('Connect Repos');
});

test('connect repos button hidden when no github app configured', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(GitHubAppService::class, $mock);

    GitHubInstallation::query()->delete();

    Volt::test('pages::projects.index')
        ->assertSee('No repos connected')
        ->assertSee('Set up GitHub App');
});

test('repo picker opens and shows repos from installation', function () {
    $this->mockAppService->shouldReceive('listRepositories')
        ->andReturn([
            'total_count' => 2,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/repo-alpha', 'description' => 'First repo', 'private' => false, 'language' => 'PHP'],
                ['id' => 2, 'full_name' => 'owner/repo-beta', 'description' => 'Second repo', 'private' => true, 'language' => 'JS'],
            ],
        ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSet('showRepoPicker', true)
        ->assertSee('owner/repo-alpha')
        ->assertSee('owner/repo-beta')
        ->assertSee('Private');
});

test('repo picker shows connected badge for registered repos', function () {
    Project::factory()->create(['repo' => 'owner/repo-alpha', 'path' => '/tmp/alpha']);

    $this->mockAppService->shouldReceive('listRepositories')
        ->andReturn([
            'total_count' => 1,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/repo-alpha', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSee('Connected');
});

test('repo picker shows connect badge for unregistered repos', function () {
    $this->mockAppService->shouldReceive('listRepositories')
        ->andReturn([
            'total_count' => 1,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/new-repo', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSee('Connect');
});

test('can register a repo from the picker', function () {
    $path = sys_get_temp_dir().'/'.uniqid('dispatch-test-');
    mkdir($path);

    try {
        $this->mockAppService->shouldReceive('listRepositories')->andReturn([
            'total_count' => 1,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/new-repo', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

        Volt::test('pages::projects.index')
            ->call('openRepoPicker')
            ->call('startRegister', 'owner/new-repo', $this->installation->id)
            ->assertSet('showRegisterModal', true)
            ->assertSet('registerRepo', 'owner/new-repo')
            ->set('registerPath', $path)
            ->call('registerProject');

        $project = Project::where('repo', 'owner/new-repo')->first();
        expect($project)->not->toBeNull();
        expect($project->path)->toBe($path);
        expect($project->github_installation_id)->toBe($this->installation->id);
    } finally {
        File::deleteDirectory($path);
    }
});

test('register validates path exists', function () {
    $this->mockAppService->shouldReceive('listRepositories')->andReturn([
        'total_count' => 0,
        'repositories' => [],
    ]);

    Volt::test('pages::projects.index')
        ->call('startRegister', 'owner/repo', $this->installation->id)
        ->set('registerPath', '/nonexistent/path')
        ->call('registerProject')
        ->assertHasErrors('registerPath');

    expect(Project::where('repo', 'owner/repo')->exists())->toBeFalse();
});

test('can unregister a repo from the picker', function () {
    Project::factory()->create(['repo' => 'owner/to-remove', 'path' => '/tmp/remove']);

    $this->mockAppService->shouldReceive('listRepositories')->andReturn([
        'total_count' => 1,
        'repositories' => [
            ['id' => 1, 'full_name' => 'owner/to-remove', 'description' => '', 'private' => false, 'language' => null],
        ],
    ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->call('unregisterRepo', 'owner/to-remove');

    expect(Project::where('repo', 'owner/to-remove')->exists())->toBeFalse();
});

test('repo picker closes when close button clicked', function () {
    $this->mockAppService->shouldReceive('listRepositories')->andReturn([
        'total_count' => 0,
        'repositories' => [],
    ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSet('showRepoPicker', true)
        ->call('closeRepoPicker')
        ->assertSet('showRepoPicker', false);
});

test('empty state shows setup github app link when not configured', function () {
    $mock = Mockery::mock(GitHubAppService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(GitHubAppService::class, $mock);

    GitHubInstallation::query()->delete();

    Volt::test('pages::projects.index')
        ->assertSee('No repos connected')
        ->assertSee('Set up GitHub App');
});

test('repo picker shows error when api call fails', function () {
    $this->mockAppService->shouldReceive('listRepositories')
        ->andThrow(new RuntimeException('Connection refused'));

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSet('errorMessage', 'Failed to load repositories: Connection refused');
});

test('repo picker search filters results', function () {
    $this->mockAppService->shouldReceive('listRepositories')
        ->andReturn([
            'total_count' => 3,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/api-service', 'description' => '', 'private' => false, 'language' => null],
                ['id' => 2, 'full_name' => 'owner/web-app', 'description' => '', 'private' => false, 'language' => null],
                ['id' => 3, 'full_name' => 'owner/api-gateway', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSee('owner/api-service')
        ->assertSee('owner/web-app')
        ->set('repoPickerSearch', 'api')
        ->assertSee('owner/api-service')
        ->assertSee('owner/api-gateway')
        ->assertDontSee('owner/web-app');
});

test('repo picker switches between installations', function () {
    GitHubInstallation::factory()->create([
        'installation_id' => 67890,
        'account_login' => 'zorg',
    ]);

    $this->mockAppService->shouldReceive('listRepositories')
        ->with(12345, Mockery::any())
        ->andReturn([
            'total_count' => 1,
            'repositories' => [
                ['id' => 1, 'full_name' => 'owner/repo-one', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

    $this->mockAppService->shouldReceive('listRepositories')
        ->with(67890, Mockery::any())
        ->andReturn([
            'total_count' => 1,
            'repositories' => [
                ['id' => 2, 'full_name' => 'zorg/repo-two', 'description' => '', 'private' => false, 'language' => null],
            ],
        ]);

    Volt::test('pages::projects.index')
        ->call('openRepoPicker')
        ->assertSee('owner/repo-one')
        ->assertSee('owner')
        ->assertSee('zorg')
        ->call('switchInstallation', 67890)
        ->assertSet('repoPickerInstallationId', 67890)
        ->assertSee('zorg/repo-two');
});
