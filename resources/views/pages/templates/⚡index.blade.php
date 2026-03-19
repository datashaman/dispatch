<?php

use App\Models\Project;
use App\Services\TemplateInstaller;
use App\Services\TemplateRegistry;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Templates')] class extends Component {
    public string $category = 'all';
    public ?string $previewTemplateId = null;
    public ?int $selectedProjectId = null;

    public function getTemplates(): array
    {
        return app(TemplateRegistry::class)->byCategory($this->category);
    }

    public function getCategories(): array
    {
        return app(TemplateRegistry::class)->categories();
    }

    public function getProjects()
    {
        return Project::orderBy('repo')->get();
    }

    public function getInstalledRuleIds(): array
    {
        if (! $this->selectedProjectId) {
            return [];
        }

        $project = Project::find($this->selectedProjectId);
        if (! $project) {
            return [];
        }

        return app(TemplateInstaller::class)->installedRuleIds($project);
    }

    public function filterCategory(string $category): void
    {
        $this->category = $category;
    }

    public function preview(string $templateId): void
    {
        $this->previewTemplateId = $templateId;
    }

    public function closePreview(): void
    {
        $this->previewTemplateId = null;
    }

    public function install(string $templateId): void
    {
        if (! $this->selectedProjectId) {
            session()->flash('error', 'Select a project first.');
            return;
        }

        $project = Project::find($this->selectedProjectId);
        if (! $project) {
            session()->flash('error', 'Project not found.');
            return;
        }

        $result = app(TemplateInstaller::class)->install($templateId, $project);

        if ($result['success']) {
            session()->flash('success', $result['message']);
        } else {
            session()->flash('error', $result['message']);
        }
    }

    public function getPreviewTemplate(): ?array
    {
        if (! $this->previewTemplateId) {
            return null;
        }

        return app(TemplateRegistry::class)->find($this->previewTemplateId);
    }
}; ?>

<section class="w-full">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">Rule Templates</flux:heading>

            <div class="w-64">
                <flux:select wire:model.live="selectedProjectId" placeholder="Select a project…">
                    @foreach ($this->getProjects() as $project)
                        <flux:select.option :value="$project->id">{{ $project->repo }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" icon="check-circle" dismissible>
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" icon="x-circle" dismissible>
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- Category filter bar --}}
        <div class="flex gap-2">
            @foreach ($this->getCategories() as $cat)
                <flux:button
                    size="sm"
                    :variant="$category === $cat['id'] ? 'primary' : 'ghost'"
                    wire:click="filterCategory('{{ $cat['id'] }}')"
                >
                    {{ $cat['label'] }}
                </flux:button>
            @endforeach
        </div>

        {{-- Template grid --}}
        @php
            $templates = $this->getTemplates();
            $installedIds = $this->getInstalledRuleIds();
        @endphp

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @forelse ($templates as $template)
                @php
                    $isInstalled = in_array($template['rule']['id'], $installedIds);
                @endphp
                <div class="flex flex-col justify-between rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <flux:heading size="lg">{{ $template['name'] }}</flux:heading>
                            <flux:badge size="sm" variant="pill" color="zinc">{{ $template['category'] }}</flux:badge>
                        </div>
                        <flux:text class="mb-3 text-sm">{{ $template['description'] }}</flux:text>

                        <div class="mb-4 flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="font-mono">{{ $template['event'] }}</span>
                            <span>&middot;</span>
                            <span>{{ count($template['tools']) }} tools</span>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="preview('{{ $template['id'] }}')">
                            Preview
                        </flux:button>
                        @if ($isInstalled)
                            <flux:button size="sm" variant="filled" disabled>
                                Installed
                            </flux:button>
                        @else
                            <flux:button
                                size="sm"
                                variant="primary"
                                wire:click="install('{{ $template['id'] }}')"
                                :disabled="! $selectedProjectId"
                            >
                                Install
                            </flux:button>
                        @endif
                    </div>
                </div>
            @empty
                <flux:text class="col-span-2 py-8 text-center text-zinc-500">No templates in this category.</flux:text>
            @endforelse
        </div>

        {{-- Preview modal --}}
        @if ($previewTemplateId)
            @php $previewTemplate = $this->getPreviewTemplate(); @endphp
            @if ($previewTemplate)
                <flux:modal wire:model="previewTemplateId" class="max-w-2xl">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ $previewTemplate['name'] }}</flux:heading>
                        <flux:text>{{ $previewTemplate['description'] }}</flux:text>

                        <div>
                            <flux:heading size="sm" class="mb-2">dispatch.yml rule</flux:heading>
                            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-zinc-800">{{ \Symfony\Component\Yaml\Yaml::dump($previewTemplate['rule'], 10, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) }}</pre>
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="ghost" wire:click="closePreview">Close</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif
        @endif
    </div>
</section>
