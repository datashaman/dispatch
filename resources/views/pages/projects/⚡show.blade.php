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
        return Project::find($this->projectId);
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
                <flux:button variant="primary" icon="cog-6-tooth" :href="route('config.index', $project)" wire:navigate>
                    {{ __('Config Editor') }}
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
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('API Key') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ !empty($project->agent_secrets['api_key']) ? __('Configured') : __('Not set') }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <flux:text variant="subtle" class="text-xs uppercase tracking-wider">{{ __('Cache Config') }}</flux:text>
                    <flux:heading size="sm" class="mt-1">{{ $project->cache_config ? __('Enabled') : __('Disabled') }}</flux:heading>
                </div>
            </div>
        </div>

        {{-- Rules are now managed via dispatch.yml config --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <flux:heading size="lg">{{ __('Rules') }}</flux:heading>
            </div>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
                <flux:text variant="subtle">{{ __('Rules are configured in dispatch.yml. Use import/export to manage.') }}</flux:text>
            </div>
        </div>
    @endif
</section>
