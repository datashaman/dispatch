<?php

use App\Models\Project;
use App\Services\ConfigLoader;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rules')] class extends Component {
    public int $projectId;
    public string $errorMessage = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    #[Computed]
    public function config(): ?object
    {
        try {
            return app(ConfigLoader::class)->load($this->project->path);
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to load dispatch.yml: {$e->getMessage()}";

            return null;
        }
    }

    public function getEvents(): array
    {
        return config('dispatch.events', []);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Rules') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->project->repo }} &mdash; {{ __('Rules are defined in dispatch.yml. Edit the file to change rules.') }}
            </flux:text>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('projects.show', $this->project)" wire:navigate>
            {{ __('Back to Project') }}
        </flux:button>
    </div>

    @if ($errorMessage)
        <flux:callout variant="danger" class="mb-4">
            {{ $errorMessage }}
        </flux:callout>
    @endif

    @if ($this->config === null && ! $errorMessage)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ __('No dispatch.yml found') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Create a dispatch.yml file in the project root to define rules.') }}</flux:text>
        </div>
    @elseif ($this->config)
        {{-- Agent Defaults --}}
        <div class="mb-6 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <flux:heading size="sm" class="mb-2">{{ __('Agent Defaults') }}</flux:heading>
            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <flux:text variant="subtle" class="text-xs uppercase">{{ __('Name') }}</flux:text>
                    <flux:text class="font-mono">{{ $this->config->agentName }}</flux:text>
                </div>
                <div>
                    <flux:text variant="subtle" class="text-xs uppercase">{{ __('Executor') }}</flux:text>
                    <flux:text class="font-mono">{{ $this->config->agentExecutor }}</flux:text>
                </div>
                <div>
                    <flux:text variant="subtle" class="text-xs uppercase">{{ __('Provider') }}</flux:text>
                    <flux:text class="font-mono">{{ $this->config->agentProvider ?? '—' }}</flux:text>
                </div>
                <div>
                    <flux:text variant="subtle" class="text-xs uppercase">{{ __('Model') }}</flux:text>
                    <flux:text class="font-mono">{{ $this->config->agentModel ?? '—' }}</flux:text>
                </div>
            </div>
        </div>

        {{-- Rules --}}
        @if (empty($this->config->rules))
            <flux:text class="text-zinc-500">{{ __('No rules defined in dispatch.yml.') }}</flux:text>
        @else
            <div class="space-y-4">
                @foreach ($this->config->rules as $rule)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <flux:heading size="sm">{{ $rule->name ?? $rule->id }}</flux:heading>
                                <div class="flex items-center gap-2 mt-1">
                                    <flux:badge size="sm" color="blue">{{ $rule->id }}</flux:badge>
                                    <flux:badge size="sm" color="zinc">{{ $this->getEvents()[$rule->event]['label'] ?? $rule->event }}</flux:badge>
                                    @if ($rule->agent?->isolation)
                                        <flux:badge size="sm" color="amber">{{ __('Worktree') }}</flux:badge>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                @if ($rule->output?->githubComment)
                                    <flux:badge size="sm" color="green">{{ __('Comment') }}</flux:badge>
                                @endif
                                @if ($rule->output?->githubReaction)
                                    <flux:badge size="sm" color="zinc">{{ $rule->output->githubReaction }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        {{-- Filters --}}
                        @if (! empty($rule->filters))
                            <div class="mb-3">
                                <flux:text variant="subtle" class="text-xs uppercase mb-1">{{ __('Filters') }}</flux:text>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($rule->filters as $filter)
                                        <flux:badge size="sm" color="zinc" class="font-mono">
                                            {{ $filter->field }} {{ $filter->operator->value }} "{{ $filter->value }}"
                                        </flux:badge>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Tools --}}
                        @if ($rule->agent?->tools)
                            <div class="mb-3">
                                <flux:text variant="subtle" class="text-xs uppercase mb-1">{{ __('Tools') }}</flux:text>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($rule->agent->tools as $tool)
                                        <flux:badge size="sm" color="zinc">{{ $tool }}</flux:badge>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Prompt Preview --}}
                        <div>
                            <flux:text variant="subtle" class="text-xs uppercase mb-1">{{ __('Prompt') }}</flux:text>
                            <pre class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-xs font-mono whitespace-pre-wrap max-h-32 overflow-y-auto">{{ $rule->prompt }}</pre>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</section>
