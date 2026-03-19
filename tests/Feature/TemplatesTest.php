<?php

use App\Models\Project;
use App\Models\User;
use App\Services\TemplateInstaller;
use App\Services\TemplateRegistry;
use Livewire\Volt\Volt;
use Symfony\Component\Yaml\Yaml;

// --- TemplateRegistry ---

test('template registry returns all templates', function () {
    $registry = new TemplateRegistry;
    $templates = $registry->all();

    expect($templates)->toBeArray();
    expect(count($templates))->toBeGreaterThanOrEqual(5);

    foreach ($templates as $template) {
        expect($template)->toHaveKeys(['id', 'name', 'description', 'category', 'event', 'tools', 'rule']);
        expect($template['rule'])->toHaveKeys(['id', 'event', 'prompt']);
    }
});

test('template registry filters by category', function () {
    $registry = new TemplateRegistry;

    $triage = $registry->byCategory('triage');
    expect(count($triage))->toBeGreaterThanOrEqual(1);
    foreach ($triage as $t) {
        expect($t['category'])->toBe('triage');
    }

    $all = $registry->byCategory('all');
    expect($all)->toBe($registry->all());
});

test('template registry finds template by id', function () {
    $registry = new TemplateRegistry;

    $template = $registry->find('issue-triage');
    expect($template)->not->toBeNull();
    expect($template['id'])->toBe('issue-triage');
    expect($template['name'])->toBe('Issue Triage');

    expect($registry->find('nonexistent'))->toBeNull();
});

test('template registry returns categories', function () {
    $registry = new TemplateRegistry;
    $categories = $registry->categories();

    expect($categories)->toBeArray();
    $ids = array_column($categories, 'id');
    expect($ids)->toContain('all', 'triage', 'review', 'implementation', 'ci-cd');
});

// --- TemplateInstaller ---

// Helper to create a temp project with dispatch.yml
function createTempProject(array $rules = []): Project
{
    $project = Project::factory()->create(['path' => sys_get_temp_dir().'/dispatch-test-'.uniqid()]);
    mkdir($project->path, 0755, true);

    file_put_contents($project->path.'/dispatch.yml', Yaml::dump([
        'version' => 1,
        'agent' => ['name' => 'test', 'executor' => 'laravel-ai'],
        'rules' => $rules,
    ]));

    return $project;
}

function cleanupTempProject(Project $project): void
{
    $dir = $project->path;
    if (is_dir($dir)) {
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}

afterEach(function () {
    if (isset($this->tempProject)) {
        cleanupTempProject($this->tempProject);
    }
});

test('template installer installs rule into dispatch.yml', function () {
    $this->tempProject = createTempProject();

    $installer = app(TemplateInstaller::class);
    $result = $installer->install('issue-triage', $this->tempProject);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('Issue Triage');

    $data = Yaml::parseFile($this->tempProject->path.'/dispatch.yml');
    expect($data['rules'])->toHaveCount(1);
    expect($data['rules'][0]['id'])->toBe('issue-triage');
    expect($data['rules'][0]['event'])->toBe('issues.opened');
});

test('template installer rejects duplicate rule id', function () {
    $this->tempProject = createTempProject([
        ['id' => 'issue-triage', 'event' => 'issues.opened', 'prompt' => 'existing'],
    ]);

    $installer = app(TemplateInstaller::class);
    $result = $installer->install('issue-triage', $this->tempProject);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('already exists');
});

test('template installer fails gracefully with missing dispatch.yml', function () {
    $project = Project::factory()->create(['path' => '/tmp/nonexistent-'.uniqid()]);

    $installer = app(TemplateInstaller::class);
    $result = $installer->install('issue-triage', $project);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('No dispatch.yml');
});

test('template installer fails with unknown template', function () {
    $project = Project::factory()->create();

    $installer = app(TemplateInstaller::class);
    $result = $installer->install('nonexistent', $project);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('Template not found');
});

test('template installer returns installed rule ids', function () {
    $this->tempProject = createTempProject([
        ['id' => 'issue-triage', 'event' => 'issues.opened', 'prompt' => 'test'],
        ['id' => 'pr-review', 'event' => 'pull_request.opened', 'prompt' => 'test'],
    ]);

    $installer = app(TemplateInstaller::class);
    $ids = $installer->installedRuleIds($this->tempProject);

    expect($ids)->toBe(['issue-triage', 'pr-review']);
});

// --- Templates page ---

test('templates page renders for authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages::templates.index')
        ->assertSee('Rule Templates')
        ->assertSee('Issue Triage')
        ->assertSee('PR Code Review')
        ->assertSee('Interactive Q&A');
});

test('templates page filters by category', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages::templates.index')
        ->call('filterCategory', 'review')
        ->assertSee('PR Code Review')
        ->assertSee('Review Comment Responder')
        ->assertDontSee('Issue Triage');
});

test('templates page shows install button when project selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->tempProject = createTempProject();

    Volt::test('pages::templates.index')
        ->set('selectedProjectId', $this->tempProject->id)
        ->assertSee('Install');
});

test('templates page installs template to project', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->tempProject = createTempProject();

    Volt::test('pages::templates.index')
        ->set('selectedProjectId', $this->tempProject->id)
        ->call('install', 'issue-triage')
        ->assertSee('installed');

    $data = Yaml::parseFile($this->tempProject->path.'/dispatch.yml');
    expect($data['rules'])->toHaveCount(1);
    expect($data['rules'][0]['id'])->toBe('issue-triage');
});

test('templates page requires authentication', function () {
    $this->get('/templates')
        ->assertRedirect(route('login'));
});
