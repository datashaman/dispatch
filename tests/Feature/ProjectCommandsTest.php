<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('dispatch:add-project', function () {
    test('adds a project with valid repo and path', function () {
        $path = base_path();

        $this->artisan('dispatch:add-project', ['repo' => 'owner/repo', 'path' => $path])
            ->expectsOutput("Project 'owner/repo' added successfully.")
            ->assertSuccessful();

        $this->assertDatabaseHas('projects', [
            'repo' => 'owner/repo',
            'path' => $path,
        ]);
    });

    test('fails if repo already exists', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:add-project', ['repo' => 'owner/repo', 'path' => base_path()])
            ->expectsOutput("Project 'owner/repo' already exists.")
            ->assertFailed();
    });

    test('fails if path does not exist', function () {
        $this->artisan('dispatch:add-project', ['repo' => 'owner/repo', 'path' => '/nonexistent/path'])
            ->expectsOutput("Path '/nonexistent/path' does not exist or is not a directory.")
            ->assertFailed();
    });
});

describe('dispatch:remove-project', function () {
    test('removes an existing project with confirmation', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:remove-project', ['repo' => 'owner/repo'])
            ->expectsConfirmation("Are you sure you want to remove project 'owner/repo'?", 'yes')
            ->expectsOutput("Project 'owner/repo' removed successfully.")
            ->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['repo' => 'owner/repo']);
    });

    test('cancels removal when confirmation is denied', function () {
        Project::factory()->create(['repo' => 'owner/repo']);

        $this->artisan('dispatch:remove-project', ['repo' => 'owner/repo'])
            ->expectsConfirmation("Are you sure you want to remove project 'owner/repo'?", 'no')
            ->expectsOutput('Operation cancelled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('projects', ['repo' => 'owner/repo']);
    });

    test('fails if repo does not exist', function () {
        $this->artisan('dispatch:remove-project', ['repo' => 'owner/repo'])
            ->expectsOutput("Project 'owner/repo' does not exist.")
            ->assertFailed();
    });
});

describe('dispatch:list-projects', function () {
    test('lists all registered projects', function () {
        Project::factory()->create(['repo' => 'owner/repo-a', 'path' => '/path/a']);
        Project::factory()->create(['repo' => 'owner/repo-b', 'path' => '/path/b']);

        $this->artisan('dispatch:list-projects')
            ->expectsTable(['Repo', 'Path'], [
                ['repo' => 'owner/repo-a', 'path' => '/path/a'],
                ['repo' => 'owner/repo-b', 'path' => '/path/b'],
            ])
            ->assertSuccessful();
    });

    test('shows message when no projects registered', function () {
        $this->artisan('dispatch:list-projects')
            ->expectsOutput('No projects registered.')
            ->assertSuccessful();
    });
});
