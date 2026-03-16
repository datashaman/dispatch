<?php

use App\Models\Project;
use App\Services\ConfigSyncer;
use App\Services\DefaultRulesService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    public string $newRepo = '';
    public string $newPath = '';
    public bool $showAddForm = false;
    public bool $showEditForm = false;
    public ?int $editingProjectId = null;
    public string $editRepo = '';
    public string $editPath = '';
    public ?string $editAgentName = null;
    public ?string $editAgentExecutor = null;
    public ?string $editAgentProvider = null;
    public ?string $editAgentModel = null;
    public ?string $editAgentInstructionsFile = null;
    public bool $editCacheConfig = false;
    public ?int $confirmingDelete = null;
    public string $statusMessage = '';
    public string $errorMessage = '';

    public function updatedNewPath(): void
    {
        if (! File::isDirectory($this->newPath)) {
            return;
        }

        $result = Process::path($this->newPath)->run('git remote get-url origin');

        if ($result->successful()) {
            $url = trim($result->output());
            // Extract owner/repo from SSH or HTTPS URLs
            if (preg_match('#[:/]([^/]+/[^/]+?)(?:\.git)?$#', $url, $matches)) {
                $this->newRepo = $matches[1];
            }
        }
    }

    public function getProviders(): array
    {
        return config('dispatch.providers', []);
    }

    public function getModelsForProvider(?string $provider): array
    {
        if (! $provider) {
            return [];
        }

        return config("dispatch.providers.{$provider}.models", []);
    }

    public function updatedEditAgentProvider(): void
    {
        $models = $this->getModelsForProvider($this->editAgentProvider);
        if (! array_key_exists($this->editAgentModel ?? '', $models)) {
            $this->editAgentModel = $models ? array_key_first($models) : null;
        }
    }

    public function editProject(int $id): void
    {
        $project = Project::findOrFail($id);
        $this->editingProjectId = $project->id;
        $this->editRepo = $project->repo;
        $this->editPath = $project->path;
        $this->editAgentName = $project->agent_name;
        $this->editAgentExecutor = $project->agent_executor;
        $this->editAgentProvider = $project->agent_provider;
        $this->editAgentModel = $project->agent_model;
        $this->editAgentInstructionsFile = $project->agent_instructions_file;
        $this->editCacheConfig = (bool) $project->cache_config;
        $this->showEditForm = true;
    }

    public function updateProject(): void
    {
        $this->validate([
            'editRepo' => ['required', 'string', 'unique:projects,repo,'.$this->editingProjectId],
            'editPath' => ['required', 'string'],
        ], [
            'editRepo.unique' => 'This repository is already registered.',
        ]);

        if (! File::isDirectory($this->editPath)) {
            $this->addError('editPath', 'The path does not exist on disk.');

            return;
        }

        $project = Project::findOrFail($this->editingProjectId);
        $project->update([
            'repo' => $this->editRepo,
            'path' => $this->editPath,
            'agent_name' => $this->editAgentName,
            'agent_executor' => $this->editAgentExecutor,
            'agent_provider' => $this->editAgentProvider,
            'agent_model' => $this->editAgentModel,
            'agent_instructions_file' => $this->editAgentInstructionsFile,
            'cache_config' => $this->editCacheConfig,
        ]);

        $this->reset('showEditForm', 'editingProjectId');
        $this->dispatch('project-updated');
    }

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

        $project = Project::create([
            'repo' => $this->newRepo,
            'path' => $this->newPath,
        ]);

        app(DefaultRulesService::class)->seed($project);

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

<section class="w-full">
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
                    <flux:label>{{ __('Local Path') }}</flux:label>
                    <flux:input wire:model.live.debounce.500ms="newPath" placeholder="/path/to/repo" required />
                    <flux:description>{{ __('Path to a local git clone. The repository will be detected automatically.') }}</flux:description>
                    <flux:error name="newPath" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Repository') }}</flux:label>
                    <flux:input wire:model="newRepo" placeholder="owner/repo" required />
                    <flux:error name="newRepo" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showAddForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Add Project') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Edit Project Form --}}
    <flux:modal wire:model="showEditForm" class="md:w-2xl">
        <form wire:submit="updateProject">
            <flux:heading size="lg">{{ __('Edit Project') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Update repository settings and agent configuration.') }}</flux:text>

            <div class="mt-6 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Repository') }}</flux:label>
                        <flux:input wire:model="editRepo" placeholder="owner/repo" required />
                        <flux:error name="editRepo" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Local Path') }}</flux:label>
                        <flux:input wire:model="editPath" placeholder="/path/to/repo" required />
                        <flux:error name="editPath" />
                    </flux:field>
                </div>

                <flux:separator />

                <flux:heading size="sm">{{ __('Agent Defaults') }}</flux:heading>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('Agent Name') }}</flux:label>
                        <flux:input wire:model="editAgentName" placeholder="e.g. sparky" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Executor') }}</flux:label>
                        <flux:select wire:model="editAgentExecutor">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            <flux:select.option value="laravel-ai">{{ __('Laravel AI') }}</flux:select.option>
                            <flux:select.option value="claude-cli">{{ __('Claude CLI') }}</flux:select.option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Instructions File') }}</flux:label>
                        <flux:input wire:model="editAgentInstructionsFile" placeholder="e.g. SPARKY.md" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Provider') }}</flux:label>
                        <flux:select wire:model.live="editAgentProvider">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach ($this->getProviders() as $key => $provider)
                                <flux:select.option value="{{ $key }}">{{ $provider['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Model') }}</flux:label>
                        <flux:select wire:model="editAgentModel" :disabled="! $editAgentProvider">
                            <flux:select.option value="">{{ $editAgentProvider ? __('Select a model') : __('Select a provider first') }}</flux:select.option>
                            @foreach ($this->getModelsForProvider($editAgentProvider) as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:switch wire:model="editCacheConfig" label="{{ __('Cache Config') }}" description="{{ __('Cache parsed dispatch.yml for this project.') }}" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showEditForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('Save Changes') }}</flux:button>
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
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:underline">
                                <flux:heading size="sm">{{ $project->repo }}</flux:heading>
                            </a>
                            <flux:text class="mt-1 truncate font-mono text-xs">{{ $project->path }}</flux:text>
                        </div>

                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="bolt" :href="route('rules.index', $project)" wire:navigate>
                                {{ __('Rules') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="editProject({{ $project->id }})">
                                {{ __('Edit') }}
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
