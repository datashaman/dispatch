<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Services\ConfigSyncer;
use App\Services\DefaultRulesService;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
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

    // Repo picker state
    public bool $showRepoPicker = false;
    public string $repoPickerSearch = '';
    public string $repoPickerSort = 'full_name';
    public string $repoPickerDirection = 'asc';
    public ?int $repoPickerInstallationId = null;

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

    #[Computed]
    public function isGitHubAppConfigured(): bool
    {
        return app(GitHubAppService::class)->isConfigured();
    }

    #[Computed]
    public function installations(): \Illuminate\Database\Eloquent\Collection
    {
        return GitHubInstallation::orderBy('account_login')->get();
    }

    #[Computed]
    public function registeredRepos(): array
    {
        return Project::pluck('repo')->toArray();
    }

    protected function allPickerRepos(): array
    {
        if (! $this->repoPickerInstallationId) {
            return [];
        }

        return Cache::remember(
            "github_repos_{$this->repoPickerInstallationId}",
            300,
            function (): array {
                $service = app(GitHubAppService::class);
                $repos = [];
                $page = 1;

                do {
                    $result = $service->listRepositories(
                        $this->repoPickerInstallationId,
                        $page,
                    );
                    $repos = array_merge($repos, $result['repositories'] ?? []);
                    $page++;
                } while (count($result['repositories'] ?? []) === 100);

                return $repos;
            },
        );
    }

    #[Computed]
    public function pickerRepos(): array
    {
        if (! $this->showRepoPicker || ! $this->repoPickerInstallationId) {
            return [];
        }

        try {
            $allRepos = $this->allPickerRepos();

            if ($this->repoPickerSearch !== '') {
                $allRepos = array_values(array_filter($allRepos, function (array $repo): bool {
                    return stripos($repo['full_name'], $this->repoPickerSearch) !== false
                        || stripos($repo['description'] ?? '', $this->repoPickerSearch) !== false;
                }));
            }

            usort($allRepos, function (array $a, array $b): int {
                $valA = $a[$this->repoPickerSort] ?? '';
                $valB = $b[$this->repoPickerSort] ?? '';
                $cmp = strnatcasecmp((string) $valA, (string) $valB);

                return $this->repoPickerDirection === 'desc' ? -$cmp : $cmp;
            });

            return $allRepos;
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to load repositories: {$e->getMessage()}";

            return [];
        }
    }

    public function openRepoPicker(?int $installationId = null): void
    {
        $installations = $this->installations;

        if ($installations->isEmpty()) {
            $this->errorMessage = 'No GitHub App installations found. Configure your GitHub App in Settings first.';

            return;
        }

        $this->repoPickerInstallationId = $installationId ?? $installations->first()->installation_id;
        $this->repoPickerSearch = '';
        $this->repoPickerSort = 'full_name';
        $this->repoPickerDirection = 'asc';
        $this->showRepoPicker = true;
        unset($this->pickerRepos);
    }

    public function closeRepoPicker(): void
    {
        $this->showRepoPicker = false;
    }

    public function switchInstallation(int $installationId): void
    {
        $this->repoPickerInstallationId = $installationId;
        unset($this->pickerRepos);
    }

    public function updatedRepoPickerSearch(): void
    {
        unset($this->pickerRepos);
    }

    public function updatedRepoPickerSort(): void
    {
        unset($this->pickerRepos);
    }

    public function updatedRepoPickerDirection(): void
    {
        unset($this->pickerRepos);
    }

    public function connectRepo(string $fullName, int $installationDbId): void
    {
        if (Project::where('repo', $fullName)->exists()) {
            $this->errorMessage = "Repository {$fullName} is already connected.";

            return;
        }

        $installation = GitHubInstallation::find($installationDbId);

        if (! $installation) {
            $this->errorMessage = 'Installation not found.';

            return;
        }

        $repoPath = storage_path("repos/{$fullName}");

        try {
            if (! File::isDirectory($repoPath)) {
                $token = app(GitHubAppService::class)->getInstallationToken($installation->installation_id);
                $cloneUrl = "https://x-access-token:{$token}@github.com/{$fullName}.git";

                File::ensureDirectoryExists(dirname($repoPath));

                $result = Process::run("git clone {$cloneUrl} {$repoPath}");

                if (! $result->successful()) {
                    $this->errorMessage = "Failed to clone {$fullName}: {$result->errorOutput()}";

                    return;
                }
            }

            $project = Project::create([
                'repo' => $fullName,
                'path' => $repoPath,
                'github_installation_id' => $installationDbId,
            ]);

            app(DefaultRulesService::class)->seed($project);

            unset($this->registeredRepos, $this->pickerRepos);
            $this->statusMessage = "Connected: {$fullName}";
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to connect {$fullName}: {$e->getMessage()}";
        }
    }

    public function unregisterRepo(string $repo): void
    {
        $project = Project::where('repo', $repo)->first();

        if ($project) {
            $project->delete();
            unset($this->registeredRepos, $this->pickerRepos);
            $this->statusMessage = "Project removed: {$repo}";
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

    public function removeProject(int $id): void
    {
        Project::findOrFail($id)->delete();
        $this->confirmingDelete = null;
        unset($this->registeredRepos);
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

    public function toggleEnabled(int $id): void
    {
        $project = Project::findOrFail($id);
        $project->update(['enabled' => ! $project->enabled]);
        $this->statusMessage = $project->enabled
            ? "{$project->repo} is now live."
            : "{$project->repo} is now paused.";
    }

}; ?>

<section class="w-full">
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Repositories connected to webhook dispatch.') }}</flux:text>
        </div>
        @if ($this->isGitHubAppConfigured && $this->installations->isNotEmpty())
            <flux:button variant="primary" icon="cloud" wire:click="openRepoPicker">
                {{ __('Connect Repos') }}
            </flux:button>
        @endif
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

    {{-- Repo Picker Panel --}}
    @if ($showRepoPicker)
        <div class="mb-6 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Your Repositories') }}</flux:heading>
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="closeRepoPicker" />
            </div>

            {{-- Installation selector (if multiple) --}}
            @if ($this->installations->count() > 1)
                <div class="flex items-center gap-2">
                    @foreach ($this->installations as $inst)
                        <flux:button
                            variant="{{ $repoPickerInstallationId === $inst->installation_id ? 'primary' : 'ghost' }}"
                            size="sm"
                            wire:click="switchInstallation({{ $inst->installation_id }})"
                        >
                            {{ $inst->account_login }}
                        </flux:button>
                    @endforeach
                </div>
            @endif

            {{-- Search & Sort --}}
            <div class="flex items-center gap-2">
                <div class="flex-1">
                    <flux:input wire:model.live.debounce.300ms="repoPickerSearch" placeholder="Search repositories..." icon="magnifying-glass" size="sm" clearable />
                </div>
                <flux:select wire:model.live="repoPickerSort" class="w-32" size="sm">
                    <flux:select.option value="full_name">{{ __('Name') }}</flux:select.option>
                    <flux:select.option value="created_at">{{ __('Created') }}</flux:select.option>
                    <flux:select.option value="updated_at">{{ __('Updated') }}</flux:select.option>
                    <flux:select.option value="pushed_at">{{ __('Pushed') }}</flux:select.option>
                </flux:select>
                <flux:button variant="ghost" size="sm" wire:click="$set('repoPickerDirection', '{{ $repoPickerDirection === 'asc' ? 'desc' : 'asc' }}')" icon="{{ $repoPickerDirection === 'asc' ? 'bars-arrow-up' : 'bars-arrow-down' }}" />
            </div>

            {{-- Repo list --}}
            <div class="space-y-1" wire:loading.class="opacity-50">
                @forelse ($this->pickerRepos as $repo)
                    @php $isRegistered = in_array($repo['full_name'], $this->registeredRepos, true); @endphp
                    <div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800/50" wire:key="picker-{{ $repo['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium truncate">{{ $repo['full_name'] }}</span>
                                @if ($repo['private'])
                                    <flux:badge color="amber" size="sm">{{ __('Private') }}</flux:badge>
                                @endif
                            </div>
                            @if ($repo['description'])
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">{{ $repo['description'] }}</p>
                            @endif
                        </div>
                        <div>
                            @if ($isRegistered)
                                <flux:button variant="ghost" size="sm" wire:click="unregisterRepo('{{ $repo['full_name'] }}')" wire:confirm="Remove {{ $repo['full_name'] }} from projects?">
                                    <flux:badge color="green" size="sm">{{ __('Connected') }}</flux:badge>
                                </flux:button>
                            @else
                                @php
                                    $installation = $this->installations->firstWhere('installation_id', $repoPickerInstallationId);
                                @endphp
                                <flux:button variant="ghost" size="sm" wire:click="connectRepo('{{ $repo['full_name'] }}', {{ $installation?->id }})" wire:confirm="Connect {{ $repo['full_name'] }}?">
                                    <flux:badge color="zinc" size="sm">{{ __('Connect') }}</flux:badge>
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    @if ($repoPickerSearch)
                        <flux:text class="text-center py-4 text-zinc-500">{{ __('No repositories match your search.') }}</flux:text>
                    @else
                        <flux:text class="text-center py-4 text-zinc-500">{{ __('No repositories found for this installation.') }}</flux:text>
                    @endif
                @endforelse
            </div>

            {{-- Repo count --}}
            @if (count($this->pickerRepos) > 0)
                <flux:text class="text-xs text-zinc-500">
                    {{ count($this->pickerRepos) }} {{ __('repositories') }}
                </flux:text>
            @endif
        </div>
    @endif

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
            <flux:heading size="lg" class="mt-4">{{ __('No repos connected') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Connect a repo to get started with webhook dispatch.') }}</flux:text>
            @if (! $this->isGitHubAppConfigured)
                <flux:button variant="ghost" class="mt-4" icon="cog-6-tooth" :href="route('github.settings')" wire:navigate>
                    {{ __('Set up GitHub App') }}
                </flux:button>
            @endif
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
                            <flux:button variant="ghost" size="sm" wire:click="toggleEnabled({{ $project->id }})">
                                @if ($project->enabled)
                                    <flux:badge color="green" size="sm">{{ __('Live') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Paused') }}</flux:badge>
                                @endif
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="bolt" :href="route('rules.index', $project)" wire:navigate>
                                {{ __('Rules') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="editProject({{ $project->id }})">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray" wire:click="importConfig({{ $project->id }})" wire:loading.attr="disabled">
                                {{ __('Import') }}
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
