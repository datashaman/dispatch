<?php

use App\Models\Project;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project Detail')] class extends Component {
    public ?int $projectId = null;

    public function mount(int $project): void
    {
        $this->projectId = $project;
    }

    public function getProject(): ?Project
    {
        return Project::with(['rules.agentConfig', 'rules.outputConfig', 'rules.retryConfig', 'rules.filters'])->find($this->projectId);
    }
}; ?>

<section class="w-full">
    @php $project = $this->getProject(); @endphp

    @if (! $project)
        <flux:callout variant="danger">
            {{ __('Project not found.') }}
        </flux:callout>
    @else
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ $project->repo }}</flux:heading>
                <flux:text class="mt-1 font-mono text-sm">{{ $project->path }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="arrow-left" :href="route('projects.index')" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <flux:button variant="primary" icon="bolt" :href="route('rules.index', $project)" wire:navigate>
                    {{ __('Manage Rules') }}
                </flux:button>
            </div>
        </div>

        {{-- Agent Configuration --}}
        <div class="mb-6">
            <flux:heading size="lg" class="mb-3">{{ __('Agent Configuration') }}</flux:heading>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Agent Name') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ $project->agent_name ?? '—' }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Executor') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ $project->agent_executor ?? '—' }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Provider') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ $project->agent_provider ?? '—' }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Model') }}</flux:text>
                    <flux:heading size="sm" class="mt-1 font-mono text-sm">{{ $project->agent_model ?? '—' }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Instructions File') }}</flux:text>
                    <flux:heading size="sm" class="mt-1 font-mono text-sm">{{ $project->agent_instructions_file ?? '—' }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Cache Config') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ $project->cache_config ? __('Enabled') : __('Disabled') }}</flux:heading>
                </div>
            </div>
        </div>

        {{-- Rules Overview --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="lg">{{ __('Rules') }} ({{ $project->rules->count() }})</flux:heading>
            </div>

            @if ($project->rules->isEmpty())
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
                    <flux:text variant="subtle">{{ __('No rules configured for this project.') }}</flux:text>
                    <flux:button variant="primary" size="sm" class="mt-3" icon="bolt" :href="route('rules.index', $project)" wire:navigate>
                        {{ __('Add Rules') }}
                    </flux:button>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Rule') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Event') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Filters') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Tools') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Isolation') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Continue on Error') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($project->rules as $rule)
                                <tr wire:key="rule-{{ $rule->id }}">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-sm">{{ $rule->name }}</div>
                                        <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $rule->rule_id }}</div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-sm">{{ $rule->event }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        @if ($rule->filters->isEmpty())
                                            <flux:text variant="subtle">{{ __('None') }}</flux:text>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($rule->filters as $filter)
                                                    <flux:badge size="sm">{{ $filter->field }} {{ $filter->operator->value }} "{{ Str::limit($filter->value, 20) }}"</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        @if ($rule->agentConfig && $rule->agentConfig->tools)
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($rule->agentConfig->tools as $tool)
                                                    <flux:badge size="sm" variant="solid">{{ $tool }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @else
                                            <flux:text variant="subtle">{{ __('Default') }}</flux:text>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        @if ($rule->agentConfig?->isolation)
                                            <flux:badge size="sm" variant="solid" color="amber">{{ __('Worktree') }}</flux:badge>
                                        @else
                                            <flux:text variant="subtle">{{ __('Off') }}</flux:text>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        @if ($rule->continue_on_error)
                                            <flux:badge size="sm" variant="solid" color="red">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:text variant="subtle">{{ __('No') }}</flux:text>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</section>
