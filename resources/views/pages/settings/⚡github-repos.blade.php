<?php

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Services\DefaultRulesService;
use App\Services\GitHubAppService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Browse Repositories')] class extends Component {
    public GitHubInstallation $installation;

    public int $page = 1;
    public int $perPage = 100;
    public int $totalCount = 0;
    public string $search = '';
    public string $sort = 'full_name';
    public string $direction = 'asc';
    public string $statusMessage = '';
    public string $errorMessage = '';
    public string $registerPath = '';
    public ?string $registerRepo = null;
    public bool $showRegisterModal = false;

    public function mount(GitHubInstallation $installation): void
    {
        $this->installation = $installation;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
        unset($this->repos);
    }

    public function updatedSort(): void
    {
        $this->page = 1;
        unset($this->repos);
    }

    public function updatedDirection(): void
    {
        $this->page = 1;
        unset($this->repos);
    }

    #[Computed]
    public function repos(): array
    {
        try {
            $allRepos = $this->allRepos();

            // Filter
            if ($this->search !== '') {
                $allRepos = array_values(array_filter($allRepos, function (array $repo): bool {
                    return stripos($repo['full_name'], $this->search) !== false
                        || stripos($repo['description'] ?? '', $this->search) !== false;
                }));
            }

            // Sort
            usort($allRepos, function (array $a, array $b): int {
                $valA = $a[$this->sort] ?? '';
                $valB = $b[$this->sort] ?? '';
                $cmp = strnatcasecmp((string) $valA, (string) $valB);

                return $this->direction === 'desc' ? -$cmp : $cmp;
            });

            $this->totalCount = count($allRepos);
            $offset = ($this->page - 1) * $this->perPage;

            return array_slice($allRepos, $offset, $this->perPage);
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to load repositories: {$e->getMessage()}";

            return [];
        }
    }

    protected function allRepos(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            "github_repos_{$this->installation->installation_id}",
            300,
            function (): array {
                $service = app(GitHubAppService::class);
                $repos = [];
                $page = 1;

                do {
                    $result = $service->listRepositories(
                        $this->installation->installation_id,
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
    public function registeredRepos(): array
    {
        return Project::pluck('repo')->toArray();
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->totalCount / $this->perPage));
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            unset($this->repos);
        }
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) {
            $this->page++;
            unset($this->repos);
        }
    }

    public function startRegister(string $fullName): void
    {
        $this->registerRepo = $fullName;
        $this->registerPath = '';
        $this->showRegisterModal = true;
    }

    public function registerProject(): void
    {
        $this->validate([
            'registerRepo' => ['required', 'string', 'unique:projects,repo'],
            'registerPath' => ['required', 'string'],
        ], [
            'registerRepo.unique' => 'This repository is already registered.',
        ]);

        if (! \Illuminate\Support\Facades\File::isDirectory($this->registerPath)) {
            $this->addError('registerPath', 'The path does not exist on disk.');

            return;
        }

        $project = Project::create([
            'repo' => $this->registerRepo,
            'path' => $this->registerPath,
            'github_installation_id' => $this->installation->id,
        ]);

        app(DefaultRulesService::class)->seed($project);

        $this->reset('showRegisterModal', 'registerRepo', 'registerPath');
        unset($this->registeredRepos);
        $this->statusMessage = "Project registered successfully.";
    }

    public function isRegistered(string $fullName): bool
    {
        return in_array($fullName, $this->registeredRepos, true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Repositories')" :subheading="$installation->account_login . ' — browse and register repos'">
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('github.settings')" wire:navigate>
                    {{ __('Back to GitHub Settings') }}
                </flux:button>
            </div>

            @if ($statusMessage)
                <flux:callout variant="success">
                    {{ $statusMessage }}
                </flux:callout>
            @endif

            @if ($errorMessage)
                <flux:callout variant="danger">
                    {{ $errorMessage }}
                </flux:callout>
            @endif

            {{-- Search & Sort --}}
            <div class="flex items-center gap-2">
                <div class="flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search repositories..." icon="magnifying-glass" clearable />
                </div>
                <flux:select wire:model.live="sort" class="w-40">
                    <flux:select.option value="full_name">{{ __('Name') }}</flux:select.option>
                    <flux:select.option value="created_at">{{ __('Created') }}</flux:select.option>
                    <flux:select.option value="updated_at">{{ __('Updated') }}</flux:select.option>
                    <flux:select.option value="pushed_at">{{ __('Pushed') }}</flux:select.option>
                </flux:select>
                <flux:button variant="ghost" size="sm" wire:click="$set('direction', '{{ $direction === 'asc' ? 'desc' : 'asc' }}')" icon="{{ $direction === 'asc' ? 'bars-arrow-up' : 'bars-arrow-down' }}" />
            </div>

            {{-- Register Modal --}}
            <flux:modal wire:model="showRegisterModal">
                <form wire:submit="registerProject">
                    <flux:heading size="lg">{{ __('Register Repository') }}</flux:heading>
                    <flux:text class="mt-1">{{ $registerRepo }}</flux:text>

                    <div class="mt-6 space-y-4">
                        <flux:field>
                            <flux:label>{{ __('Local Path') }}</flux:label>
                            <flux:input wire:model="registerPath" placeholder="/path/to/repo" required />
                            <flux:description>{{ __('The absolute path to the local clone of this repository.') }}</flux:description>
                            <flux:error name="registerPath" />
                            <flux:error name="registerRepo" />
                        </flux:field>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showRegisterModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Register') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- Repo List --}}
            @if (count($this->repos) > 0)
                <div class="space-y-2">
                    @foreach ($this->repos as $repo)
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-3" wire:key="repo-{{ $repo['id'] }}">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <flux:heading size="sm" class="truncate">{{ $repo['full_name'] }}</flux:heading>
                                    @if ($repo['description'])
                                        <flux:text class="mt-0.5 truncate text-xs">{{ $repo['description'] }}</flux:text>
                                    @endif
                                    <div class="mt-1 flex items-center gap-2">
                                        @if ($repo['private'])
                                            <flux:badge color="amber" size="sm">{{ __('Private') }}</flux:badge>
                                        @else
                                            <flux:badge color="zinc" size="sm">{{ __('Public') }}</flux:badge>
                                        @endif
                                        @if ($repo['language'])
                                            <flux:text class="text-xs">{{ $repo['language'] }}</flux:text>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    @if ($this->isRegistered($repo['full_name']))
                                        <flux:badge color="green" size="sm">{{ __('Registered') }}</flux:badge>
                                    @else
                                        <flux:button variant="primary" size="sm" icon="plus" wire:click="startRegister('{{ $repo['full_name'] }}')">
                                            {{ __('Register') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pagination --}}
                @if ($this->totalPages > 1)
                    <div class="flex items-center justify-between pt-2">
                        <flux:text class="text-sm">
                            {{ __('Page :current of :total', ['current' => $page, 'total' => $this->totalPages]) }}
                            ({{ $totalCount }} {{ __('repos') }})
                        </flux:text>
                        <div class="flex gap-2">
                            <flux:button variant="ghost" size="sm" wire:click="previousPage" :disabled="$page <= 1">
                                {{ __('Previous') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" wire:click="nextPage" :disabled="$page >= $this->totalPages">
                                {{ __('Next') }}
                            </flux:button>
                        </div>
                    </div>
                @endif
            @elseif (! $errorMessage)
                <flux:text class="text-zinc-500">
                    {{ $search !== '' ? __('No repositories match your search.') : __('No repositories found for this installation.') }}
                </flux:text>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
