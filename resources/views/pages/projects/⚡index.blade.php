<?php

use App\Models\Project;
use App\Services\ConfigSyncer;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    public string $newRepo = '';
    public string $newPath = '';
    public bool $showAddForm = false;
    public ?int $confirmingDelete = null;
    public string $statusMessage = '';
    public string $errorMessage = '';

    public function addProject(): void
    {
        $this->validate([
            'newRepo' => ['required', 'string', 'unique:projects,repo'],
            'newPath' => ['required', 'string'],
        ], [
            'newRepo.unique' => 'This repository is already registered.',
        ]);

        if (! File::isDirectory($this->newPath)) {
            $this->addError('newPath', 'The path does not exist on disk.');

            return;
        }

        Project::create([
            'repo' => $this->newRepo,
            'path' => $this->newPath,
        ]);

        $this->reset('newRepo', 'newPath', 'showAddForm');
        $this->dispatch('project-added');
    }

    public function removeProject(int $id): void
    {
        Project::findOrFail($id)->delete();
        $this->confirmingDelete = null;
        $this->dispatch('project-removed');
    }

    public function importConfig(int $id): void
    {
        $project = Project::findOrFail($id);
        $this->statusMessage = '';
        $this->errorMessage = '';

        try {
            app(ConfigSyncer::class)->import($project);
            $this->statusMessage = "Config imported for {$project->repo}.";
        } catch (\Throwable $e) {
            $this->errorMessage = "Import failed: {$e->getMessage()}";
        }
    }

    public function exportConfig(int $id): void
    {
        $project = Project::findOrFail($id);
        $this->statusMessage = '';
        $this->errorMessage = '';

        try {
            app(ConfigSyncer::class)->export($project);
            $this->statusMessage = "Config exported for {$project->repo}.";
        } catch (\Throwable $e) {
            $this->errorMessage = "Export failed: {$e->getMessage()}";
        }
    }
}; ?>

<section class="w-full max-w-4xl">
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage registered repositories and their local paths.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$set('showAddForm', true)">
            {{ __('Add Project') }}
        </flux:button>
    </div>

    @if ($statusMessage)
        <flux:callout variant="success" class="mb-4">
            {{ $statusMessage }}
        </flux:callout>
    @endif

    @if ($errorMessage)
        <flux:callout variant="danger" class="mb-4">
            {{ $errorMessage }}
        </flux:callout>
    @endif

    {{-- Add Project Form --}}
    <flux:modal wire:model="showAddForm">
        <form wire:submit="addProject">
            <flux:heading size="lg">{{ __('Add Project') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Register a GitHub repository with its local filesystem path.') }}</flux:text>

            <div class="mt-6 space-y-4">
                <flux:field>
                    <flux:label>{{ __('Repository') }}</flux:label>
                    <flux:input wire:model="newRepo" placeholder="owner/repo" required />
                    <flux:error name="newRepo" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Local Path') }}</flux:label>
                    <flux:input wire:model="newPath" placeholder="/path/to/repo" required />
                    <flux:error name="newPath" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showAddForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Add Project') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Projects List --}}
    @php $projects = \App\Models\Project::orderBy('repo')->get(); @endphp

    @if ($projects->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <flux:icon name="folder" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ __('No projects registered') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Add a project to get started with webhook dispatch.') }}</flux:text>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($projects as $project)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4" wire:key="project-{{ $project->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm">{{ $project->repo }}</flux:heading>
                            <flux:text class="mt-1 truncate font-mono text-xs">{{ $project->path }}</flux:text>
                        </div>

                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="bolt" :href="route('rules.index', $project)" wire:navigate>
                                {{ __('Rules') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray" wire:click="importConfig({{ $project->id }})" wire:loading.attr="disabled">
                                {{ __('Import') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="arrow-up-tray" wire:click="exportConfig({{ $project->id }})" wire:loading.attr="disabled">
                                {{ __('Export') }}
                            </flux:button>

                            @if ($confirmingDelete === $project->id)
                                <flux:button variant="danger" size="sm" icon="check" wire:click="removeProject({{ $project->id }})">
                                    {{ __('Confirm') }}
                                </flux:button>
                                <flux:button variant="ghost" size="sm" wire:click="$set('confirmingDelete', null)">
                                    {{ __('Cancel') }}
                                </flux:button>
                            @else
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="$set('confirmingDelete', {{ $project->id }})">
                                    {{ __('Remove') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
