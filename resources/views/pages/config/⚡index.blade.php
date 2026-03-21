<?php

use App\Enums\FilterOperator;
use App\Models\Project;
use App\Services\ConfigWriter;
use App\Services\DefaultRulesService;
use App\Services\TemplateRegistry;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

new #[Title('Config Editor')] class extends Component {
    public int $projectId;

    // Config data as raw arrays (source of truth for form state)
    public array $configData = [];
    public array $originalConfigData = [];
    public int $loadedMtime = 0;

    // UI state
    public string $statusMessage = '';
    public string $errorMessage = '';
    public ?string $expandedRuleId = null;
    public bool $allExpanded = false;
    public bool $showMtimeConflict = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->loadConfig();
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    #[Computed]
    public function events(): array
    {
        return config('dispatch.events', []);
    }

    #[Computed]
    public function filterOperators(): array
    {
        return array_map(fn (FilterOperator $op) => [
            'value' => $op->value,
            'label' => match ($op) {
                FilterOperator::Equals => 'equals',
                FilterOperator::NotEquals => 'not equals',
                FilterOperator::Contains => 'contains',
                FilterOperator::NotContains => 'not contains',
                FilterOperator::StartsWith => 'starts with',
                FilterOperator::EndsWith => 'ends with',
                FilterOperator::Matches => 'matches (regex)',
            },
        ], FilterOperator::cases());
    }

    #[Computed]
    public function templates(): array
    {
        return app(TemplateRegistry::class)->all();
    }

    #[Computed]
    public function isDirty(): bool
    {
        return $this->configData !== $this->originalConfigData;
    }

    #[Computed]
    public function yamlPreview(): string
    {
        if (empty($this->configData)) {
            return '';
        }

        return app(ConfigWriter::class)->arrayToYaml($this->prepareForSave($this->configData));
    }

    #[Computed]
    public function dependencyWarnings(): array
    {
        $warnings = [];
        $ruleIds = array_column($this->configData['rules'] ?? [], 'id');

        foreach ($this->configData['rules'] ?? [] as $rule) {
            foreach ($rule['depends_on'] ?? [] as $dep) {
                if (! in_array($dep, $ruleIds, true)) {
                    $warnings[$rule['id']][] = "Depends on '{$dep}' which does not exist";
                }
            }
        }

        return $warnings;
    }

    public function loadConfig(): void
    {
        $project = $this->project;
        $filePath = rtrim($project->path, '/').'/dispatch.yml';

        if (! file_exists($filePath)) {
            $this->configData = [];
            $this->originalConfigData = [];
            $this->loadedMtime = 0;

            return;
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to parse dispatch.yml: {$e->getMessage()}";

            return;
        }

        if (! is_array($data)) {
            $this->errorMessage = 'dispatch.yml must contain a YAML mapping.';

            return;
        }

        // Normalize rules to ensure consistent structure
        if (isset($data['rules']) && is_array($data['rules'])) {
            $data['rules'] = array_values(array_map(
                fn ($rule) => $this->normalizeRule($rule),
                $data['rules']
            ));
        } else {
            $data['rules'] = [];
        }

        $this->configData = $data;
        $this->originalConfigData = $data;
        $this->loadedMtime = app(ConfigWriter::class)->getMtime($project->path) ?? 0;
        $this->showMtimeConflict = false;
        $this->errorMessage = '';
    }

    public function generateDefaultRules(): void
    {
        try {
            $seeded = app(DefaultRulesService::class)->seed($this->project);

            if ($seeded) {
                $this->loadConfig();
                $this->statusMessage = 'Default rules generated.';
            } else {
                $this->errorMessage = 'dispatch.yml already exists.';
            }
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to generate rules: {$e->getMessage()}";
        }
    }

    public function saveConfig(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        // Validate required fields
        $errors = $this->validateConfig();
        if (! empty($errors)) {
            $this->errorMessage = implode(' ', $errors);

            return;
        }

        $result = app(ConfigWriter::class)->save(
            $this->project,
            $this->prepareForSave($this->configData),
            $this->loadedMtime
        );

        if ($result['success']) {
            $this->statusMessage = $result['message'];
            $this->originalConfigData = $this->configData;
            $this->loadedMtime = app(ConfigWriter::class)->getMtime($this->project->path) ?? 0;
            $this->showMtimeConflict = false;

            if ($result['sync_warning']) {
                $this->errorMessage = $result['sync_warning'];
            }

            unset($this->isDirty, $this->yamlPreview);
        } else {
            if (str_contains($result['message'], 'modified externally')) {
                $this->showMtimeConflict = true;
            }
            $this->errorMessage = $result['message'];
        }
    }

    public function reloadConfig(): void
    {
        $this->loadConfig();
        $this->statusMessage = 'Config reloaded from disk.';
    }

    // --- Rule Management ---

    public function addBlankRule(): void
    {
        $this->configData['rules'][] = $this->normalizeRule([
            'id' => '',
            'event' => '',
            'prompt' => '',
            'name' => '',
        ]);

        $lastIndex = count($this->configData['rules']) - 1;
        $this->expandedRuleId = (string) $lastIndex;
        unset($this->isDirty, $this->yamlPreview);
    }

    public function addFromTemplate(string $templateId): void
    {
        $template = app(TemplateRegistry::class)->find($templateId);

        if (! $template) {
            return;
        }

        // Check for duplicate rule ID
        $existingIds = array_column($this->configData['rules'] ?? [], 'id');
        $newId = $template['rule']['id'];
        if (in_array($newId, $existingIds, true)) {
            $counter = 2;
            while (in_array($newId.'-'.$counter, $existingIds, true)) {
                $counter++;
            }
            $template['rule']['id'] = $newId.'-'.$counter;
            if (isset($template['rule']['name'])) {
                $template['rule']['name'] .= " ({$counter})";
            }
        }

        $this->configData['rules'][] = $this->normalizeRule($template['rule']);

        $lastIndex = count($this->configData['rules']) - 1;
        $this->expandedRuleId = (string) $lastIndex;
        unset($this->isDirty, $this->yamlPreview);
    }

    public function duplicateRule(int $index): void
    {
        if (! isset($this->configData['rules'][$index])) {
            return;
        }

        $rule = $this->configData['rules'][$index];
        $existingIds = array_column($this->configData['rules'], 'id');

        // Generate unique ID with counter suffix
        $baseId = preg_replace('/-copy(-\d+)?$/', '', $rule['id']);
        $newId = $baseId.'-copy';
        if (in_array($newId, $existingIds, true)) {
            $counter = 2;
            while (in_array($baseId.'-copy-'.$counter, $existingIds, true)) {
                $counter++;
            }
            $newId = $baseId.'-copy-'.$counter;
        }

        $rule['id'] = $newId;
        if (! empty($rule['name'])) {
            $rule['name'] .= ' (copy)';
        }

        // Insert after the original
        array_splice($this->configData['rules'], $index + 1, 0, [$rule]);

        $this->expandedRuleId = (string) ($index + 1);
        unset($this->isDirty, $this->yamlPreview);
    }

    public function removeRule(int $index): void
    {
        if (! isset($this->configData['rules'][$index])) {
            return;
        }

        array_splice($this->configData['rules'], $index, 1);
        $this->configData['rules'] = array_values($this->configData['rules']);

        // Move focus to previous rule or null
        if ($this->expandedRuleId === (string) $index) {
            $this->expandedRuleId = $index > 0 ? (string) ($index - 1) : null;
        }

        unset($this->isDirty, $this->yamlPreview);
    }

    public function moveRule(int $index, string $direction): void
    {
        $rules = $this->configData['rules'];
        $newIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($newIndex < 0 || $newIndex >= count($rules)) {
            return;
        }

        [$rules[$index], $rules[$newIndex]] = [$rules[$newIndex], $rules[$index]];
        $this->configData['rules'] = $rules;

        if ($this->expandedRuleId === (string) $index) {
            $this->expandedRuleId = (string) $newIndex;
        }

        unset($this->isDirty, $this->yamlPreview);
    }

    public function toggleExpand(int $index): void
    {
        $key = (string) $index;
        $this->expandedRuleId = $this->expandedRuleId === $key ? null : $key;
    }

    public function toggleExpandAll(): void
    {
        $this->allExpanded = ! $this->allExpanded;
        $this->expandedRuleId = null;
    }

    // --- Filter Management ---

    public function addFilter(int $ruleIndex): void
    {
        if (! isset($this->configData['rules'][$ruleIndex])) {
            return;
        }

        $this->configData['rules'][$ruleIndex]['filters'][] = [
            'field' => '',
            'operator' => 'equals',
            'value' => '',
        ];
        unset($this->isDirty, $this->yamlPreview);
    }

    public function removeFilter(int $ruleIndex, int $filterIndex): void
    {
        if (! isset($this->configData['rules'][$ruleIndex]['filters'][$filterIndex])) {
            return;
        }

        array_splice($this->configData['rules'][$ruleIndex]['filters'], $filterIndex, 1);
        unset($this->isDirty, $this->yamlPreview);
    }

    // --- Helpers ---

    private function normalizeRule(array $rule): array
    {
        return [
            'id' => $rule['id'] ?? '',
            'name' => $rule['name'] ?? '',
            'event' => $rule['event'] ?? '',
            'prompt' => $rule['prompt'] ?? '',
            'filters' => array_values(array_map(fn ($f) => [
                'field' => $f['field'] ?? '',
                'operator' => $f['operator'] ?? 'equals',
                'value' => (string) ($f['value'] ?? ''),
            ], $rule['filters'] ?? [])),
            'output' => [
                'github_comment' => (bool) ($rule['output']['github_comment'] ?? false),
                'github_reaction' => $rule['output']['github_reaction'] ?? '',
            ],
            'agent' => [
                'provider' => $rule['agent']['provider'] ?? '',
                'model' => $rule['agent']['model'] ?? '',
                'tools' => implode(', ', $rule['agent']['tools'] ?? []),
                'isolation' => (bool) ($rule['agent']['isolation'] ?? false),
            ],
            'continue_on_error' => (bool) ($rule['continue_on_error'] ?? false),
            'depends_on' => $this->normalizeDependsOn($rule['depends_on'] ?? []),
            'retry' => [
                'enabled' => (bool) ($rule['retry']['enabled'] ?? false),
                'max_attempts' => (int) ($rule['retry']['max_attempts'] ?? 3),
                'delay' => (int) ($rule['retry']['delay'] ?? 60),
            ],
        ];
    }

    private function normalizeDependsOn(mixed $value): array
    {
        if (is_string($value)) {
            return $value ? [$value] : [];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => is_string($v) && $v !== ''));
        }

        return [];
    }

    private function validateConfig(): array
    {
        $errors = [];

        if (empty($this->configData['agent']['name'] ?? '')) {
            $errors[] = 'Agent name is required.';
        }
        if (empty($this->configData['agent']['executor'] ?? '')) {
            $errors[] = 'Agent executor is required.';
        }

        foreach ($this->configData['rules'] ?? [] as $i => $rule) {
            $num = $i + 1;
            if (empty($rule['id'])) {
                $errors[] = "Rule {$num}: ID is required.";
            }
            if (empty($rule['event'])) {
                $errors[] = "Rule {$num}: Event is required.";
            }
            if (empty($rule['prompt'])) {
                $errors[] = "Rule {$num}: Prompt is required.";
            }
        }

        // Check for duplicate rule IDs
        $ids = array_filter(array_column($this->configData['rules'] ?? [], 'id'));
        $duplicates = array_filter(array_count_values($ids), fn ($count) => $count > 1);
        foreach (array_keys($duplicates) as $dupId) {
            $errors[] = "Duplicate rule ID: '{$dupId}'.";
        }

        return $errors;
    }

    /**
     * Prepare configData for YAML output by cleaning up normalized form state
     * back to the format ConfigLoader expects.
     */
    public function updatedConfigData(): void
    {
        unset($this->isDirty, $this->yamlPreview, $this->dependencyWarnings);
    }

    /**
     * Clean up normalized form state back to YAML-compatible format.
     * Removes empty optional fields so the YAML stays clean.
     */
    private function prepareForSave(array $data): array
    {
        if (isset($data['rules'])) {
            $data['rules'] = array_map(function (array $rule) {
                $cleaned = [
                    'id' => $rule['id'],
                    'event' => $rule['event'],
                    'prompt' => $rule['prompt'],
                ];

                if (! empty($rule['name'])) {
                    $cleaned['name'] = $rule['name'];
                }

                if (! empty($rule['filters'])) {
                    $cleaned['filters'] = $rule['filters'];
                }

                // Output — only include non-default values
                $output = $rule['output'] ?? [];
                $outputClean = [];
                if (! empty($output['github_comment'])) {
                    $outputClean['github_comment'] = true;
                }
                if (! empty($output['github_reaction'])) {
                    $outputClean['github_reaction'] = $output['github_reaction'];
                }
                if (! empty($outputClean)) {
                    $cleaned['output'] = $outputClean;
                }

                // Agent overrides — only include non-empty values
                $agent = $rule['agent'] ?? [];
                $agentClean = [];
                if (! empty($agent['provider'])) {
                    $agentClean['provider'] = $agent['provider'];
                }
                if (! empty($agent['model'])) {
                    $agentClean['model'] = $agent['model'];
                }
                if (! empty($agent['tools'])) {
                    // Convert comma-separated string back to array
                    $agentClean['tools'] = array_map('trim', explode(',', $agent['tools']));
                    $agentClean['tools'] = array_values(array_filter($agentClean['tools']));
                }
                if (! empty($agent['isolation'])) {
                    $agentClean['isolation'] = true;
                }
                if (! empty($agentClean)) {
                    $cleaned['agent'] = $agentClean;
                }

                if (! empty($rule['continue_on_error'])) {
                    $cleaned['continue_on_error'] = true;
                }

                if (! empty($rule['depends_on'])) {
                    $cleaned['depends_on'] = $rule['depends_on'];
                }

                // Retry — only include if enabled
                $retry = $rule['retry'] ?? [];
                if (! empty($retry['enabled'])) {
                    $cleaned['retry'] = [
                        'enabled' => true,
                        'max_attempts' => (int) ($retry['max_attempts'] ?? 3),
                        'delay' => (int) ($retry['delay'] ?? 60),
                    ];
                }

                return $cleaned;
            }, $data['rules']);
        }

        return $data;
    }
}; ?>

<section
    class="w-full"
    x-data="{ saving: false }"
    x-on:keydown.meta.s.window.prevent="if (!saving) { saving = true; $wire.saveConfig().then(() => saving = false); }"
    x-on:keydown.ctrl.s.window.prevent="if (!saving) { saving = true; $wire.saveConfig().then(() => saving = false); }"
>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Config Editor') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->project->repo }}
            </flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('projects.show', $this->project)" wire:navigate>
                {{ __('Back to Project') }}
            </flux:button>
            @if (! empty($configData))
                <flux:button
                    variant="primary"
                    icon="arrow-down-tray"
                    wire:click="saveConfig"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="saveConfig">
                        {{ __('Save to dispatch.yml') }}
                        @if ($this->isDirty)
                            <span class="ml-1 text-indigo-200">●</span>
                        @endif
                    </span>
                    <span wire:loading wire:target="saveConfig">{{ __('Saving…') }}</span>
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Status Messages --}}
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

    @if ($showMtimeConflict)
        <div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <flux:icon name="exclamation-triangle" class="h-5 w-5 text-amber-500" />
                <flux:text class="text-amber-200">{{ __('dispatch.yml was modified outside the editor.') }}</flux:text>
            </div>
            <flux:button size="sm" wire:click="reloadConfig">{{ __('Reload') }}</flux:button>
        </div>
    @endif

    {{-- Empty State --}}
    @if (empty($configData) && ! $errorMessage)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">{{ __('No dispatch.yml found') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Generate a starter config with default rules for issue triage, implementation, Q&A, and code review.') }}</flux:text>
            <flux:button variant="primary" class="mt-4" icon="bolt" wire:click="generateDefaultRules">
                {{ __('Generate Default Rules') }}
            </flux:button>
        </div>
    @elseif (! empty($configData))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- LEFT: Form Panel --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                    <flux:text class="font-medium text-sm">{{ __('Rules & Agent Config') }}</flux:text>
                </div>
                <div class="p-4 space-y-6 max-h-[70vh] overflow-y-auto">
                    {{-- Agent Defaults --}}
                    <div>
                        <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 font-medium mb-3">
                            {{ __('Agent Defaults') }}
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <flux:input
                                label="{{ __('Name') }}"
                                wire:model.live.debounce.300ms="configData.agent.name"
                                placeholder="dispatch-agent"
                                size="sm"
                            />
                            <flux:select
                                label="{{ __('Executor') }}"
                                wire:model.live="configData.agent.executor"
                                size="sm"
                            >
                                <option value="">{{ __('Select...') }}</option>
                                <option value="laravel-ai">laravel-ai</option>
                                <option value="claude-cli">claude-cli</option>
                            </flux:select>
                            <flux:input
                                label="{{ __('Provider') }}"
                                wire:model.live.debounce.300ms="configData.agent.provider"
                                placeholder="anthropic"
                                size="sm"
                            />
                            <flux:input
                                label="{{ __('Model') }}"
                                wire:model.live.debounce.300ms="configData.agent.model"
                                placeholder="claude-sonnet-4-6"
                                size="sm"
                            />
                        </div>
                    </div>

                    {{-- Rules --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 font-medium">
                                {{ __('Rules') }} ({{ count($configData['rules'] ?? []) }})
                            </div>
                            @if (count($configData['rules'] ?? []) > 1)
                                <flux:button size="sm" variant="ghost" wire:click="toggleExpandAll">
                                    {{ $allExpanded ? __('Collapse All') : __('Expand All') }}
                                </flux:button>
                            @endif
                        </div>

                        <div class="space-y-2">
                            @foreach ($configData['rules'] ?? [] as $ruleIndex => $rule)
                                @php
                                    $isExpanded = $allExpanded || $expandedRuleId === (string) $ruleIndex;
                                    $hasWarning = ! empty($this->dependencyWarnings[$rule['id'] ?? '']);
                                @endphp
                                <div
                                    class="rounded-lg border {{ $isExpanded ? 'border-indigo-500/50' : 'border-zinc-200 dark:border-zinc-700' }} overflow-hidden"
                                    wire:key="rule-{{ $ruleIndex }}"
                                >
                                    {{-- Rule Header --}}
                                    <div
                                        class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                        wire:click="toggleExpand({{ $ruleIndex }})"
                                    >
                                        <flux:icon name="{{ $isExpanded ? 'chevron-down' : 'chevron-right' }}" class="h-4 w-4 shrink-0 text-zinc-400" />
                                        <div class="min-w-0 flex-1 flex items-center gap-2">
                                            <span class="text-sm font-medium truncate">
                                                {{ $rule['name'] ?: $rule['id'] ?: __('Untitled Rule') }}
                                            </span>
                                            @if ($rule['event'])
                                                <flux:badge size="sm" color="indigo">{{ $rule['event'] }}</flux:badge>
                                            @endif
                                            @if (! empty($rule['depends_on']))
                                                <flux:badge size="sm" color="green">depends_on</flux:badge>
                                            @endif
                                            @if ($hasWarning)
                                                <flux:icon name="exclamation-triangle" class="h-4 w-4 text-amber-500" />
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0" x-on:click.stop>
                                            <flux:button size="sm" variant="ghost" wire:click="moveRule({{ $ruleIndex }}, 'up')" :disabled="$ruleIndex === 0" title="{{ __('Move up') }}">
                                                ▲
                                            </flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="moveRule({{ $ruleIndex }}, 'down')" :disabled="$ruleIndex === count($configData['rules']) - 1" title="{{ __('Move down') }}">
                                                ▼
                                            </flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="duplicateRule({{ $ruleIndex }})" title="{{ __('Duplicate') }}">
                                                <flux:icon name="document-duplicate" class="h-3.5 w-3.5" />
                                            </flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="removeRule({{ $ruleIndex }})" wire:confirm="{{ __('Remove this rule?') }}" class="text-red-500 hover:text-red-400" title="{{ __('Remove') }}">
                                                ✕
                                            </flux:button>
                                        </div>
                                    </div>

                                    {{-- Rule Body (expanded) --}}
                                    @if ($isExpanded)
                                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 space-y-3">
                                            {{-- Dependency warnings --}}
                                            @if ($hasWarning)
                                                @foreach ($this->dependencyWarnings[$rule['id']] as $warning)
                                                    <div class="text-xs text-amber-500 flex items-center gap-1">
                                                        <flux:icon name="exclamation-triangle" class="h-3 w-3" />
                                                        {{ $warning }}
                                                    </div>
                                                @endforeach
                                            @endif

                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <flux:input
                                                    label="{{ __('Rule ID') }}"
                                                    wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.id"
                                                    placeholder="my-rule"
                                                    size="sm"
                                                />
                                                <flux:input
                                                    label="{{ __('Name') }}"
                                                    wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.name"
                                                    placeholder="My Rule"
                                                    size="sm"
                                                />
                                            </div>

                                            <flux:select
                                                label="{{ __('Event') }}"
                                                wire:model.live="configData.rules.{{ $ruleIndex }}.event"
                                                size="sm"
                                            >
                                                <option value="">{{ __('Select event...') }}</option>
                                                @foreach ($this->events as $eventKey => $eventData)
                                                    <option value="{{ $eventKey }}">{{ $eventKey }} — {{ $eventData['label'] }}</option>
                                                @endforeach
                                            </flux:select>

                                            {{-- Filters --}}
                                            <div>
                                                <div class="flex items-center justify-between mb-2">
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Filters') }}</span>
                                                    <flux:button size="sm" variant="ghost" wire:click="addFilter({{ $ruleIndex }})">
                                                        + {{ __('Add Filter') }}
                                                    </flux:button>
                                                </div>
                                                @foreach ($rule['filters'] ?? [] as $filterIndex => $filter)
                                                    <div class="flex items-end gap-2 mb-2" wire:key="filter-{{ $ruleIndex }}-{{ $filterIndex }}">
                                                        <div class="flex-1 min-w-0">
                                                            <flux:input
                                                                wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.filters.{{ $filterIndex }}.field"
                                                                placeholder="event.action"
                                                                size="sm"
                                                            />
                                                        </div>
                                                        <div class="w-32 shrink-0">
                                                            <flux:select
                                                                wire:model.live="configData.rules.{{ $ruleIndex }}.filters.{{ $filterIndex }}.operator"
                                                                size="sm"
                                                            >
                                                                @foreach ($this->filterOperators as $op)
                                                                    <option value="{{ $op['value'] }}">{{ $op['label'] }}</option>
                                                                @endforeach
                                                            </flux:select>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <flux:input
                                                                wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.filters.{{ $filterIndex }}.value"
                                                                placeholder="value"
                                                                size="sm"
                                                            />
                                                        </div>
                                                        <flux:button size="sm" variant="ghost" class="text-red-500 shrink-0" wire:click="removeFilter({{ $ruleIndex }}, {{ $filterIndex }})">
                                                            ✕
                                                        </flux:button>
                                                    </div>
                                                @endforeach
                                            </div>

                                            {{-- Prompt --}}
                                            <div>
                                                <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Prompt') }}</label>
                                                <textarea
                                                    wire:model.live.debounce.500ms="configData.rules.{{ $ruleIndex }}.prompt"
                                                    class="w-full rounded-md border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm font-mono text-zinc-900 dark:text-zinc-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
                                                    rows="4"
                                                    placeholder="{{ __('Describe what the agent should do...') }}"
                                                ></textarea>
                                            </div>

                                            {{-- Output Config --}}
                                            <div class="grid grid-cols-2 gap-3">
                                                <div class="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        wire:model.live="configData.rules.{{ $ruleIndex }}.output.github_comment"
                                                        class="rounded border-zinc-300 dark:border-zinc-600 text-indigo-500 focus:ring-indigo-500"
                                                    >
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Post comment') }}</span>
                                                </div>
                                                <flux:input
                                                    label="{{ __('Reaction') }}"
                                                    wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.output.github_reaction"
                                                    placeholder="eyes"
                                                    size="sm"
                                                />
                                            </div>

                                            {{-- Advanced (collapsible) --}}
                                            <details class="text-sm">
                                                <summary class="cursor-pointer text-xs text-zinc-500 dark:text-zinc-400 hover:text-zinc-300">
                                                    {{ __('Advanced Options') }}
                                                </summary>
                                                <div class="mt-3 space-y-3">
                                                    <div class="grid grid-cols-2 gap-3">
                                                        <flux:input
                                                            label="{{ __('Agent Provider Override') }}"
                                                            wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.agent.provider"
                                                            placeholder="{{ __('Inherit from defaults') }}"
                                                            size="sm"
                                                        />
                                                        <flux:input
                                                            label="{{ __('Agent Model Override') }}"
                                                            wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.agent.model"
                                                            placeholder="{{ __('Inherit from defaults') }}"
                                                            size="sm"
                                                        />
                                                    </div>
                                                    <flux:input
                                                        label="{{ __('Tools (comma-separated)') }}"
                                                        wire:model.live.debounce.300ms="configData.rules.{{ $ruleIndex }}.agent.tools"
                                                        placeholder="Read, Edit, Bash, Glob, Grep"
                                                        size="sm"
                                                    />
                                                    <div class="flex items-center gap-4">
                                                        <div class="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                wire:model.live="configData.rules.{{ $ruleIndex }}.agent.isolation"
                                                                class="rounded border-zinc-300 dark:border-zinc-600 text-indigo-500 focus:ring-indigo-500"
                                                            >
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Worktree isolation') }}</span>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                wire:model.live="configData.rules.{{ $ruleIndex }}.continue_on_error"
                                                                class="rounded border-zinc-300 dark:border-zinc-600 text-indigo-500 focus:ring-indigo-500"
                                                            >
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Continue on error') }}</span>
                                                        </div>
                                                    </div>

                                                    {{-- Dependencies --}}
                                                    <div x-data="{ value: @js(implode(', ', $rule['depends_on'] ?? [])) }">
                                                        <flux:input
                                                            label="{{ __('Depends On (comma-separated rule IDs)') }}"
                                                            x-model="value"
                                                            x-on:change="$wire.set('configData.rules.{{ $ruleIndex }}.depends_on', value.split(',').map(s => s.trim()).filter(s => s))"
                                                            placeholder="analyze, review"
                                                            size="sm"
                                                        />
                                                    </div>

                                                    {{-- Retry --}}
                                                    <div class="flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            wire:model.live="configData.rules.{{ $ruleIndex }}.retry.enabled"
                                                            class="rounded border-zinc-300 dark:border-zinc-600 text-indigo-500 focus:ring-indigo-500"
                                                        >
                                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Enable retry') }}</span>
                                                    </div>
                                                    @if ($rule['retry']['enabled'] ?? false)
                                                        <div class="grid grid-cols-2 gap-3">
                                                            <flux:input
                                                                label="{{ __('Max Attempts') }}"
                                                                type="number"
                                                                wire:model.live="configData.rules.{{ $ruleIndex }}.retry.max_attempts"
                                                                size="sm"
                                                            />
                                                            <flux:input
                                                                label="{{ __('Delay (seconds)') }}"
                                                                type="number"
                                                                wire:model.live="configData.rules.{{ $ruleIndex }}.retry.delay"
                                                                size="sm"
                                                            />
                                                        </div>
                                                    @endif
                                                </div>
                                            </details>
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            {{-- Add Rule --}}
                            <div class="flex gap-2">
                                <flux:button variant="ghost" class="flex-1 border border-dashed border-zinc-300 dark:border-zinc-700 hover:border-indigo-500" wire:click="addBlankRule">
                                    + {{ __('Blank Rule') }}
                                </flux:button>
                                <div class="flex-1" x-data="{ templateId: '' }">
                                    <flux:select
                                        x-model="templateId"
                                        x-on:change="if (templateId) { $wire.addFromTemplate(templateId); templateId = ''; }"
                                        size="sm"
                                    >
                                        <option value="">+ {{ __('From Template…') }}</option>
                                        @foreach ($this->templates as $template)
                                            <option value="{{ $template['id'] }}">{{ $template['name'] }}</option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT: YAML Preview --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden hidden lg:block">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                    <flux:text class="font-medium text-sm">dispatch.yml</flux:text>
                    @if ($this->isDirty)
                        <flux:badge size="sm" color="amber">{{ __('Unsaved changes') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="green">{{ __('In sync') }}</flux:badge>
                    @endif
                </div>
                <pre class="p-4 text-xs font-mono text-zinc-400 dark:text-zinc-500 overflow-auto max-h-[70vh] whitespace-pre leading-relaxed">{{ $this->yamlPreview }}</pre>
            </div>
        </div>

        {{-- Status Bar --}}
        <div class="mt-4 px-3 py-2 rounded-lg text-xs flex items-center justify-between {{ $this->isDirty ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400' : 'bg-green-500/10 border border-green-500/20 text-green-400' }}">
            <span>
                {{ count($configData['rules'] ?? []) }} {{ Str::plural('rule', count($configData['rules'] ?? [])) }} configured
                · {{ $this->isDirty ? __('Unsaved changes') : __('In sync') }}
            </span>
            <span class="text-zinc-500">{{ __('Cmd+S to save') }}</span>
        </div>
    @endif
</section>
