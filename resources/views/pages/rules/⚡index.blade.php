<?php

use App\DataTransferObjects\RuleConfig;
use App\Models\Project;
use App\Services\ConfigLoader;
use App\Services\DefaultRulesService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rules')] class extends Component {
    public int $projectId;
    public string $statusMessage = '';
    public string $errorMessage = '';
    public ?string $expandedRuleId = null;

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

    public function generateDefaultRules(): void
    {
        try {
            $seeded = app(DefaultRulesService::class)->seed($this->project);

            if ($seeded) {
                unset($this->config);
                $this->statusMessage = 'Default rules generated. You can edit dispatch.yml to customize them.';
            } else {
                $this->errorMessage = 'dispatch.yml already exists.';
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to generate rules: {$e->getMessage()}";
        }
    }

    public function toggleExpand(string $ruleId): void
    {
        $this->expandedRuleId = $this->expandedRuleId === $ruleId ? null : $ruleId;
    }

    public function getRuleSummary(RuleConfig $rule): string
    {
        $parts = [];

        $events = config('dispatch.events', []);
        $eventLabel = $events[$rule->event]['label'] ?? $rule->event;
        $parts[] = 'When ' . $eventLabel;

        $filterParts = [];
        foreach ($rule->filters as $filter) {
            $field = str_replace('event.', '', $filter->field);
            $field = str_replace('.', ' ', $field);
            $op = match ($filter->operator->value) {
                'equals' => 'is',
                'not_equals' => 'is not',
                'contains' => 'contains',
                'not_contains' => 'does not contain',
                'starts_with' => 'starts with',
                'ends_with' => 'ends with',
                'matches' => 'matches',
            };
            $filterParts[] = "{$field} {$op} \"{$filter->value}\"";
        }
        if ($filterParts) {
            $parts[] = 'and ' . implode(' and ', $filterParts);
        }

        $name = $rule->name ?: $rule->id;
        $parts[] = "→ {$name}";

        return implode(' ', $parts);
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

    @if ($this->config === null && ! $errorMessage)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ __('No dispatch.yml found') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Generate a starter config with default rules for issue triage, implementation, Q&A, and code review.') }}</flux:text>
            <flux:button variant="primary" class="mt-4" icon="bolt" wire:click="generateDefaultRules">
                {{ __('Generate Default Rules') }}
            </flux:button>
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
            <div class="space-y-2">
                @foreach ($this->config->rules as $rule)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden" wire:key="rule-{{ $rule->id }}">
                        {{-- Summary Row --}}
                        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" wire:click="toggleExpand('{{ $rule->id }}')">
                            <flux:icon name="{{ $expandedRuleId === $rule->id ? 'chevron-down' : 'chevron-right' }}" class="h-4 w-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0 flex-1">
                                <span class="text-sm">{{ $this->getRuleSummary($rule) }}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if ($rule->continueOnError)
                                    <flux:badge size="sm" color="amber">{{ __('Continue on error') }}</flux:badge>
                                @endif
                                @if ($rule->agent?->isolation)
                                    <flux:badge size="sm" color="sky">{{ __('Worktree') }}</flux:badge>
                                @endif
                                <flux:badge size="sm">{{ $rule->id }}</flux:badge>
                            </div>
                        </div>

                        {{-- Expanded Detail --}}
                        @if ($expandedRuleId === $rule->id)
                            <div class="border-t border-zinc-200 dark:border-zinc-700">
                                {{-- Tools Bar --}}
                                <div class="flex items-center justify-between bg-zinc-50 dark:bg-zinc-800/50 px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        @if ($rule->agent?->tools)
                                            @foreach ($rule->agent->tools as $tool)
                                                <flux:badge size="sm">{{ $tool }}</flux:badge>
                                            @endforeach
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($rule->output?->githubComment)
                                            <flux:badge size="sm" color="green">{{ __('Comment') }}</flux:badge>
                                        @endif
                                        @if ($rule->output?->githubReaction)
                                            <flux:badge size="sm" color="zinc">{{ $rule->output->githubReaction }}</flux:badge>
                                        @endif
                                        @if ($rule->retry?->enabled)
                                            <flux:badge size="sm" color="purple">{{ __('Retry') }} &times;{{ $rule->retry->maxAttempts }}</flux:badge>
                                        @endif
                                    </div>
                                </div>

                                {{-- Prompt Preview --}}
                                @if ($rule->prompt)
                                    <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3">
                                        <pre class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 p-3 text-xs font-mono text-amber-900 dark:text-amber-200 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $rule->prompt }}</pre>
                                    </div>
                                @endif

                                {{-- Filters already shown in summary line --}}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</section>
