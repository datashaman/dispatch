<?php

use App\Enums\FilterOperator;
use App\Models\Filter;
use App\Models\Project;
use App\Models\Rule;
use App\Models\RuleAgentConfig;
use App\Models\RuleOutputConfig;
use App\Models\RuleRetryConfig;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rules & Filters')] class extends Component {
    public ?int $projectId = null;

    // Rule form
    public bool $showRuleForm = false;
    public ?int $editingRuleId = null;
    public string $ruleId = '';
    public string $ruleName = '';
    public string $ruleEvent = '';
    public string $rulePrompt = '';
    public bool $ruleContinueOnError = false;
    public int $ruleSortOrder = 0;

    // Filter form
    public bool $showFilterForm = false;
    public ?int $filterForRuleId = null;
    public ?int $editingFilterId = null;
    public string $filterId = '';
    public string $filterField = '';
    public string $filterOperator = 'equals';
    public string $filterValue = '';
    public int $filterSortOrder = 0;

    // Agent config form
    public bool $showAgentConfigForm = false;
    public ?int $agentConfigForRuleId = null;
    public string $agentProvider = '';
    public string $agentModel = '';
    public ?int $agentMaxTokens = null;
    public string $agentTools = '';
    public string $agentDisallowedTools = '';
    public bool $agentIsolation = false;

    // Output config form
    public bool $showOutputConfigForm = false;
    public ?int $outputConfigForRuleId = null;
    public bool $outputLog = true;
    public bool $outputGithubComment = false;
    public string $outputGithubReaction = '';

    // Retry config form
    public bool $showRetryConfigForm = false;
    public ?int $retryConfigForRuleId = null;
    public bool $retryEnabled = false;
    public int $retryMaxAttempts = 3;
    public int $retryDelay = 60;

    // Prompt preview
    public bool $showPromptPreview = false;
    public ?int $previewRuleId = null;

    // Delete confirmation
    public ?int $confirmingDeleteRule = null;
    public ?int $confirmingDeleteFilter = null;

    // Messages
    public string $statusMessage = '';
    public string $errorMessage = '';

    public function mount(int $project): void
    {
        $this->projectId = $project;
    }

    public function getProject(): ?Project
    {
        return Project::find($this->projectId);
    }

    public function getRules()
    {
        return Rule::where('project_id', $this->projectId)
            ->orderBy('sort_order')
            ->with(['filters', 'agentConfig', 'outputConfig', 'retryConfig'])
            ->get();
    }

    // --- Rule CRUD ---

    public function openAddRule(): void
    {
        $this->resetRuleForm();
        $this->showRuleForm = true;
    }

    public function openEditRule(int $id): void
    {
        $rule = Rule::findOrFail($id);
        $this->editingRuleId = $rule->id;
        $this->ruleId = $rule->rule_id;
        $this->ruleName = $rule->name ?? '';
        $this->ruleEvent = $rule->event;
        $this->rulePrompt = $rule->prompt ?? '';
        $this->ruleContinueOnError = $rule->continue_on_error;
        $this->ruleSortOrder = $rule->sort_order ?? 0;
        $this->showRuleForm = true;
    }

    public function saveRule(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        $rules = [
            'ruleId' => ['required', 'string'],
            'ruleEvent' => ['required', 'string'],
        ];

        if (! $this->editingRuleId) {
            $rules['ruleId'][] = \Illuminate\Validation\Rule::unique('rules', 'rule_id')
                ->where('project_id', $this->projectId);
        }

        $this->validate($rules, [
            'ruleId.unique' => 'This rule ID already exists for this project.',
        ]);

        $data = [
            'project_id' => $this->projectId,
            'rule_id' => $this->ruleId,
            'name' => $this->ruleName,
            'event' => $this->ruleEvent,
            'prompt' => $this->rulePrompt,
            'continue_on_error' => $this->ruleContinueOnError,
            'sort_order' => $this->ruleSortOrder,
        ];

        if ($this->editingRuleId) {
            $rule = Rule::findOrFail($this->editingRuleId);
            $rule->update($data);
            $this->statusMessage = "Rule '{$this->ruleId}' updated.";
        } else {
            Rule::create($data);
            $this->statusMessage = "Rule '{$this->ruleId}' created.";
        }

        $this->resetRuleForm();
        $this->showRuleForm = false;
    }

    public function deleteRule(int $id): void
    {
        $rule = Rule::findOrFail($id);
        $ruleId = $rule->rule_id;
        $rule->delete();
        $this->confirmingDeleteRule = null;
        $this->statusMessage = "Rule '{$ruleId}' deleted.";
    }

    private function resetRuleForm(): void
    {
        $this->editingRuleId = null;
        $this->ruleId = '';
        $this->ruleName = '';
        $this->ruleEvent = '';
        $this->rulePrompt = '';
        $this->ruleContinueOnError = false;
        $this->ruleSortOrder = 0;
    }

    // --- Filter CRUD ---

    public function openAddFilter(int $ruleId): void
    {
        $this->resetFilterForm();
        $this->filterForRuleId = $ruleId;
        $this->showFilterForm = true;
    }

    public function openEditFilter(int $id): void
    {
        $filter = Filter::findOrFail($id);
        $this->editingFilterId = $filter->id;
        $this->filterForRuleId = $filter->rule_id;
        $this->filterId = $filter->filter_id ?? '';
        $this->filterField = $filter->field;
        $this->filterOperator = $filter->operator->value;
        $this->filterValue = $filter->value;
        $this->filterSortOrder = $filter->sort_order ?? 0;
        $this->showFilterForm = true;
    }

    public function saveFilter(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        $this->validate([
            'filterField' => ['required', 'string'],
            'filterOperator' => ['required', 'string', \Illuminate\Validation\Rule::in(array_column(FilterOperator::cases(), 'value'))],
            'filterValue' => ['required', 'string'],
        ]);

        $data = [
            'rule_id' => $this->filterForRuleId,
            'filter_id' => $this->filterId ?: null,
            'field' => $this->filterField,
            'operator' => $this->filterOperator,
            'value' => $this->filterValue,
            'sort_order' => $this->filterSortOrder,
        ];

        if ($this->editingFilterId) {
            $filter = Filter::findOrFail($this->editingFilterId);
            $filter->update($data);
            $this->statusMessage = 'Filter updated.';
        } else {
            Filter::create($data);
            $this->statusMessage = 'Filter added.';
        }

        $this->resetFilterForm();
        $this->showFilterForm = false;
    }

    public function deleteFilter(int $id): void
    {
        Filter::findOrFail($id)->delete();
        $this->confirmingDeleteFilter = null;
        $this->statusMessage = 'Filter deleted.';
    }

    private function resetFilterForm(): void
    {
        $this->editingFilterId = null;
        $this->filterForRuleId = null;
        $this->filterId = '';
        $this->filterField = '';
        $this->filterOperator = 'equals';
        $this->filterValue = '';
        $this->filterSortOrder = 0;
    }

    public function getEvents(): array
    {
        return config('dispatch.events', []);
    }

    public function getEventVariables(?string $event): array
    {
        return config("dispatch.events.{$event}.variables", []);
    }

    public function updatedRuleName(): void
    {
        if (! $this->editingRuleId) {
            $this->ruleId = Str::slug($this->ruleName);
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

    public function updatedAgentProvider(): void
    {
        $models = $this->getModelsForProvider($this->agentProvider);
        if (! array_key_exists($this->agentModel, $models)) {
            $this->agentModel = $models ? array_key_first($models) : '';
        }
    }

    // --- Agent Config ---

    public function openAgentConfig(int $ruleId): void
    {
        $rule = Rule::with('agentConfig')->findOrFail($ruleId);
        $this->agentConfigForRuleId = $ruleId;
        $config = $rule->agentConfig;

        $this->agentProvider = $config?->provider ?? '';
        $this->agentModel = $config?->model ?? '';
        $this->agentMaxTokens = $config?->max_tokens;
        $this->agentTools = $config?->tools ? implode(', ', $config->tools) : '';
        $this->agentDisallowedTools = $config?->disallowed_tools ? implode(', ', $config->disallowed_tools) : '';
        $this->agentIsolation = $config?->isolation ?? false;

        $this->showAgentConfigForm = true;
    }

    public function saveAgentConfig(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        $data = [
            'provider' => $this->agentProvider ?: null,
            'model' => $this->agentModel ?: null,
            'max_tokens' => $this->agentMaxTokens,
            'tools' => $this->agentTools ? array_map('trim', explode(',', $this->agentTools)) : null,
            'disallowed_tools' => $this->agentDisallowedTools ? array_map('trim', explode(',', $this->agentDisallowedTools)) : null,
            'isolation' => $this->agentIsolation,
        ];

        RuleAgentConfig::updateOrCreate(
            ['rule_id' => $this->agentConfigForRuleId],
            $data,
        );

        $this->statusMessage = 'Agent config saved.';
        $this->showAgentConfigForm = false;
    }

    // --- Output Config ---

    public function openOutputConfig(int $ruleId): void
    {
        $rule = Rule::with('outputConfig')->findOrFail($ruleId);
        $this->outputConfigForRuleId = $ruleId;
        $config = $rule->outputConfig;

        $this->outputLog = $config?->log ?? true;
        $this->outputGithubComment = $config?->github_comment ?? false;
        $this->outputGithubReaction = $config?->github_reaction ?? '';

        $this->showOutputConfigForm = true;
    }

    public function saveOutputConfig(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        RuleOutputConfig::updateOrCreate(
            ['rule_id' => $this->outputConfigForRuleId],
            [
                'log' => $this->outputLog,
                'github_comment' => $this->outputGithubComment,
                'github_reaction' => $this->outputGithubReaction ?: null,
            ],
        );

        $this->statusMessage = 'Output config saved.';
        $this->showOutputConfigForm = false;
    }

    // --- Retry Config ---

    public function openRetryConfig(int $ruleId): void
    {
        $rule = Rule::with('retryConfig')->findOrFail($ruleId);
        $this->retryConfigForRuleId = $ruleId;
        $config = $rule->retryConfig;

        $this->retryEnabled = $config?->enabled ?? false;
        $this->retryMaxAttempts = $config?->max_attempts ?? 3;
        $this->retryDelay = $config?->delay ?? 60;

        $this->showRetryConfigForm = true;
    }

    public function saveRetryConfig(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        RuleRetryConfig::updateOrCreate(
            ['rule_id' => $this->retryConfigForRuleId],
            [
                'enabled' => $this->retryEnabled,
                'max_attempts' => $this->retryMaxAttempts,
                'delay' => $this->retryDelay,
            ],
        );

        $this->statusMessage = 'Retry config saved.';
        $this->showRetryConfigForm = false;
    }

    // --- Expand/Collapse ---

    public ?int $expandedRuleId = null;

    public function toggleExpand(int $ruleId): void
    {
        $this->expandedRuleId = $this->expandedRuleId === $ruleId ? null : $ruleId;
    }

    // --- Prompt Preview ---

    public function openPromptPreview(int $ruleId): void
    {
        $this->previewRuleId = $ruleId;
        $this->showPromptPreview = true;
    }

    public function getTemplateVariables(string $prompt): array
    {
        preg_match_all('/\{\{\s*event\.([^}]+?)\s*\}\}/', $prompt, $matches);

        return array_unique($matches[1] ?? []);
    }

    public function getRuleSummary(Rule $rule): string
    {
        $parts = [];

        // Event description
        $eventLabel = config("dispatch.events.{$rule->event}.label", $rule->event);
        $parts[] = 'When ' . $eventLabel;

        // Filters
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

        // Action
        $name = $rule->name ?: $rule->rule_id;
        $parts[] = "→ {$name}";

        // Output
        $outputs = [];
        if ($rule->outputConfig?->github_comment) {
            $outputs[] = 'post comment';
        }
        if ($rule->outputConfig?->github_reaction) {
            $outputs[] = 'react ' . $rule->outputConfig->github_reaction;
        }
        if ($outputs) {
            $parts[] = '(' . implode(', ', $outputs) . ')';
        }

        return implode(' ', $parts);
    }
}; ?>

<section class="w-full">
    @php
        $project = $this->getProject();
        $rules = $this->getRules();
    @endphp

    @if (! $project)
        <flux:callout variant="danger">
            {{ __('Project not found.') }}
        </flux:callout>
    @else
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Rules & Filters') }}</flux:heading>
                <flux:text class="mt-1">{{ $project->repo }} &mdash; {{ __('Manage webhook dispatch rules.') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="arrow-left" :href="route('projects.index')" wire:navigate>
                    {{ __('Projects') }}
                </flux:button>
                <flux:button variant="primary" icon="plus" wire:click="openAddRule">
                    {{ __('Add Rule') }}
                </flux:button>
            </div>
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

        {{-- Rule Form Modal --}}
        <flux:modal wire:model="showRuleForm" class="md:w-2xl">
            <form wire:submit="saveRule">
                <flux:heading size="lg">{{ $editingRuleId ? __('Edit Rule') : __('New Rule') }}</flux:heading>

                <div class="mt-6 space-y-6">
                    {{-- Narrative: When... --}}
                    <div>
                        <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('When...') }}</flux:text>
                        <flux:field>
                            <flux:select wire:model.live="ruleEvent" required>
                                <flux:select.option value="">{{ __('Choose an event...') }}</flux:select.option>
                                @foreach ($this->getEvents() as $key => $event)
                                    <flux:select.option value="{{ $key }}">{{ $event['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="ruleEvent" />
                        </flux:field>
                    </div>

                    {{-- Narrative: Then run... --}}
                    <div>
                        <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Then run...') }}</flux:text>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input wire:model.live.debounce.300ms="ruleName" placeholder="e.g. Analyze Issue" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Rule ID') }}</flux:label>
                                <flux:input wire:model="ruleId" placeholder="auto-generated from name" required :disabled="(bool) $editingRuleId" />
                                <flux:error name="ruleId" />
                            </flux:field>
                        </div>
                    </div>

                    {{-- Narrative: With this prompt... --}}
                    <div>
                        <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('With this prompt...') }}</flux:text>
                        <flux:field>
                            <flux:textarea wire:model="rulePrompt" placeholder="Describe what the agent should do..." rows="5" />
                        </flux:field>

                        @if ($ruleEvent)
                            @php $vars = $this->getEventVariables($ruleEvent); @endphp
                            @if (count($vars) > 0)
                                <div class="mt-2">
                                    <flux:text variant="subtle" class="text-xs mb-1">{{ __('Available variables for this event:') }}</flux:text>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($vars as $var)
                                            @php $templateTag = '{{ event.' . $var . ' }}'; @endphp
                                            <span class="inline-flex items-center rounded-md bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-mono text-blue-700 dark:text-blue-300 cursor-pointer hover:bg-blue-100 dark:hover:bg-blue-900/50" title="{{ __('Click to copy') }}" x-data x-on:click="navigator.clipboard.writeText(@js($templateTag))">
                                                {{ $var }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>

                    {{-- Options --}}
                    <div class="flex items-center gap-6 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                        <flux:field>
                            <flux:label>{{ __('Sort Order') }}</flux:label>
                            <flux:input wire:model="ruleSortOrder" type="number" class="w-24" />
                        </flux:field>

                        <flux:field class="pt-6">
                            <flux:switch wire:model="ruleContinueOnError" label="{{ __('Continue on error') }}" description="{{ __('Keep processing remaining rules even if this one fails.') }}" />
                        </flux:field>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showRuleForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ $editingRuleId ? __('Save Changes') : __('Create Rule') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Filter Form Modal --}}
        <flux:modal wire:model="showFilterForm">
            <form wire:submit="saveFilter">
                <flux:heading size="lg">{{ $editingFilterId ? __('Edit Condition') : __('Add Condition') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Only trigger this rule when...') }}</flux:text>

                <div class="mt-6 space-y-4">
                    {{-- Narrative: the [field] [operator] [value] --}}
                    <div class="flex items-start gap-3">
                        <flux:text class="pt-2 text-sm font-medium text-zinc-500 shrink-0">{{ __('the') }}</flux:text>
                        <flux:field class="flex-1">
                            <flux:input wire:model="filterField" placeholder="e.g. event.label.name" required />
                            <flux:error name="filterField" />
                        </flux:field>
                    </div>

                    <div class="flex items-start gap-3">
                        <flux:field class="w-48 shrink-0">
                            <flux:select wire:model="filterOperator">
                                <flux:select.option value="equals">{{ __('is') }}</flux:select.option>
                                <flux:select.option value="not_equals">{{ __('is not') }}</flux:select.option>
                                <flux:select.option value="contains">{{ __('contains') }}</flux:select.option>
                                <flux:select.option value="not_contains">{{ __('does not contain') }}</flux:select.option>
                                <flux:select.option value="starts_with">{{ __('starts with') }}</flux:select.option>
                                <flux:select.option value="ends_with">{{ __('ends with') }}</flux:select.option>
                                <flux:select.option value="matches">{{ __('matches (regex)') }}</flux:select.option>
                            </flux:select>
                            <flux:error name="filterOperator" />
                        </flux:field>
                        <flux:field class="flex-1">
                            <flux:input wire:model="filterValue" placeholder="e.g. sparky" required />
                            <flux:error name="filterValue" />
                        </flux:field>
                    </div>

                    {{-- Advanced --}}
                    <div class="flex items-center gap-4 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                        <flux:field class="flex-1">
                            <flux:label>{{ __('Filter ID') }}</flux:label>
                            <flux:input wire:model="filterId" placeholder="e.g. label-check" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Order') }}</flux:label>
                            <flux:input wire:model="filterSortOrder" type="number" class="w-20" />
                        </flux:field>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showFilterForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ $editingFilterId ? __('Save') : __('Add Condition') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Agent Config Modal --}}
        <flux:modal wire:model="showAgentConfigForm">
            <form wire:submit="saveAgentConfig">
                <flux:heading size="lg">{{ __('Agent Configuration') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Configure the AI agent for this rule.') }}</flux:text>

                <div class="mt-6 space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Provider') }}</flux:label>
                        <flux:select wire:model.live="agentProvider">
                            <flux:select.option value="">{{ __('Use project default') }}</flux:select.option>
                            @foreach ($this->getProviders() as $key => $provider)
                                <flux:select.option value="{{ $key }}">{{ $provider['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Model') }}</flux:label>
                        <flux:select wire:model="agentModel" :disabled="! $agentProvider">
                            <flux:select.option value="">{{ $agentProvider ? __('Select a model') : __('Select a provider first') }}</flux:select.option>
                            @foreach ($this->getModelsForProvider($agentProvider) as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Max Tokens') }}</flux:label>
                        <flux:input wire:model="agentMaxTokens" type="number" placeholder="4096" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Tools') }}</flux:label>
                        <flux:input wire:model="agentTools" placeholder="e.g. Read, Glob, Grep, Bash" />
                        <flux:text variant="subtle" class="text-xs">{{ __('Tools the agent can use. Leave empty for all tools. Available: Read, Edit, Write, Bash, Glob, Grep.') }}</flux:text>
                    </flux:field>

                    @if ($this->getProject()?->agent_executor === 'claude-cli')
                        <flux:field>
                            <flux:label>{{ __('Disallowed Tools') }}</flux:label>
                            <flux:input wire:model="agentDisallowedTools" placeholder="e.g. Edit, Write" />
                            <flux:text variant="subtle" class="text-xs">{{ __('Claude CLI only. Block these tools entirely, even if listed above.') }}</flux:text>
                        </flux:field>
                    @endif

                    <flux:field>
                        <flux:switch wire:model="agentIsolation" label="{{ __('Isolation (git worktree)') }}" />
                    </flux:field>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showAgentConfigForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Output Config Modal --}}
        <flux:modal wire:model="showOutputConfigForm">
            <form wire:submit="saveOutputConfig">
                <flux:heading size="lg">{{ __('Output Configuration') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Configure where agent output is sent.') }}</flux:text>

                <div class="mt-6 space-y-4">
                    <flux:field>
                        <flux:switch wire:model="outputLog" label="{{ __('Log output') }}" />
                    </flux:field>

                    <flux:field>
                        <flux:switch wire:model="outputGithubComment" label="{{ __('Post as GitHub comment') }}" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('GitHub Reaction') }}</flux:label>
                        <flux:input wire:model="outputGithubReaction" placeholder="eyes, rocket, etc." />
                    </flux:field>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showOutputConfigForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Retry Config Modal --}}
        <flux:modal wire:model="showRetryConfigForm">
            <form wire:submit="saveRetryConfig">
                <flux:heading size="lg">{{ __('Retry Configuration') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Configure retry behavior for failed agent runs.') }}</flux:text>

                <div class="mt-6 space-y-4">
                    <flux:field>
                        <flux:switch wire:model="retryEnabled" label="{{ __('Enable retries') }}" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Max Attempts') }}</flux:label>
                        <flux:input wire:model="retryMaxAttempts" type="number" class="w-24" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Delay (seconds)') }}</flux:label>
                        <flux:input wire:model="retryDelay" type="number" class="w-24" />
                    </flux:field>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showRetryConfigForm', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Prompt Preview Modal --}}
        <flux:modal wire:model="showPromptPreview">
            @if ($previewRuleId)
                @php
                    $previewRule = \App\Models\Rule::find($previewRuleId);
                @endphp
                @if ($previewRule && $previewRule->prompt)
                    <flux:heading size="lg">{{ __('Prompt Preview') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Rule:') }} {{ $previewRule->rule_id }}</flux:text>

                    <div class="mt-4">
                        <flux:label>{{ __('Template') }}</flux:label>
                        <pre class="mt-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-4 text-sm font-mono whitespace-pre-wrap">{{ $previewRule->prompt }}</pre>
                    </div>

                    @php
                        $vars = $this->getTemplateVariables($previewRule->prompt);
                    @endphp
                    @if (count($vars) > 0)
                        <div class="mt-4">
                            <flux:label>{{ __('Template Variables') }}</flux:label>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($vars as $var)
                                    <span class="inline-flex items-center rounded-md bg-blue-50 dark:bg-blue-900/30 px-2 py-1 text-xs font-mono text-blue-700 dark:text-blue-300">
                                        event.{{ $var }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <flux:text>{{ __('No prompt configured for this rule.') }}</flux:text>
                @endif
            @endif

            <div class="mt-6 flex justify-end">
                <flux:button variant="ghost" wire:click="$set('showPromptPreview', false)">{{ __('Close') }}</flux:button>
            </div>
        </flux:modal>

        {{-- Rules List --}}
        @if ($rules->isEmpty())
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <flux:icon name="bolt" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No rules configured') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Add a rule to start dispatching webhooks to agents.') }}</flux:text>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($rules as $rule)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden" wire:key="rule-{{ $rule->id }}">
                        {{-- Summary Row --}}
                        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" wire:click="toggleExpand({{ $rule->id }})">
                            <flux:icon name="{{ $expandedRuleId === $rule->id ? 'chevron-down' : 'chevron-right' }}" class="h-4 w-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0 flex-1">
                                <span class="text-sm">{{ $this->getRuleSummary($rule) }}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if ($rule->continue_on_error)
                                    <flux:badge size="sm" color="amber">{{ __('Continue on error') }}</flux:badge>
                                @endif
                                @if ($rule->agentConfig?->isolation)
                                    <flux:badge size="sm" color="sky">{{ __('Worktree') }}</flux:badge>
                                @endif
                                <flux:badge size="sm">{{ $rule->rule_id }}</flux:badge>
                            </div>
                        </div>

                        {{-- Expanded Detail --}}
                        @if ($expandedRuleId === $rule->id)
                            <div class="border-t border-zinc-200 dark:border-zinc-700">
                                {{-- Actions Bar --}}
                                <div class="flex items-center justify-between bg-zinc-50 dark:bg-zinc-800/50 px-4 py-2">
                                    <div class="flex items-center gap-2">
                                        @if ($rule->agentConfig?->tools)
                                            @foreach ($rule->agentConfig->tools as $tool)
                                                <flux:badge size="sm">{{ $tool }}</flux:badge>
                                            @endforeach
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <flux:dropdown>
                                            <flux:button variant="ghost" size="sm" icon="cog-6-tooth" icon-trailing="chevron-down">
                                                {{ __('Configure') }}
                                            </flux:button>

                                            <flux:menu>
                                                <flux:menu.item icon="pencil" wire:click="openEditRule({{ $rule->id }})">{{ __('Edit Rule') }}</flux:menu.item>
                                                @if ($rule->prompt)
                                                    <flux:menu.item icon="eye" wire:click="openPromptPreview({{ $rule->id }})">{{ __('View Prompt') }}</flux:menu.item>
                                                @endif
                                                <flux:menu.separator />
                                                <flux:menu.item icon="cpu-chip" wire:click="openAgentConfig({{ $rule->id }})">{{ __('Agent Settings') }}</flux:menu.item>
                                                <flux:menu.item icon="arrow-up-tray" wire:click="openOutputConfig({{ $rule->id }})">{{ __('Output Settings') }}</flux:menu.item>
                                                <flux:menu.item icon="arrow-path" wire:click="openRetryConfig({{ $rule->id }})">{{ __('Retry Settings') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>

                                        @if ($confirmingDeleteRule === $rule->id)
                                            <flux:button variant="danger" size="sm" icon="check" wire:click="deleteRule({{ $rule->id }})">
                                                {{ __('Confirm') }}
                                            </flux:button>
                                            <flux:button variant="ghost" size="sm" wire:click="$set('confirmingDeleteRule', null)">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                        @else
                                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="$set('confirmingDeleteRule', {{ $rule->id }})">
                                                {{ __('Delete') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>

                                {{-- Prompt Preview --}}
                                @if ($rule->prompt)
                                    <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3">
                                        <pre class="text-xs font-mono text-zinc-500 dark:text-zinc-400 whitespace-pre-wrap line-clamp-4">{{ $rule->prompt }}</pre>
                                    </div>
                                @endif

                                {{-- Filters --}}
                                <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-100/50 dark:bg-zinc-800 px-4 py-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <flux:text variant="subtle" class="text-xs font-medium uppercase tracking-wide">{{ __('Filters') }}</flux:text>
                                        <flux:button variant="ghost" size="sm" icon="plus" wire:click="openAddFilter({{ $rule->id }})">
                                            {{ __('Add') }}
                                        </flux:button>
                                    </div>

                                    @if ($rule->filters->isEmpty())
                                        <flux:text variant="subtle" class="text-xs italic">{{ __('No filters — matches all events of this type.') }}</flux:text>
                                    @else
                                        <div class="space-y-1">
                                            @foreach ($rule->filters as $filter)
                                                <div class="flex items-center justify-between rounded-lg bg-white dark:bg-zinc-900 px-3 py-2 border border-zinc-200 dark:border-zinc-700" wire:key="filter-{{ $filter->id }}">
                                                    <div class="flex items-center gap-2 font-mono text-xs">
                                                        <span class="text-zinc-700 dark:text-zinc-300">{{ $filter->field }}</span>
                                                        <flux:badge size="sm" color="zinc">{{ $filter->operator->value }}</flux:badge>
                                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">"{{ $filter->value }}"</span>
                                                    </div>
                                                    <div class="flex items-center gap-1">
                                                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditFilter({{ $filter->id }})" />
                                                        @if ($confirmingDeleteFilter === $filter->id)
                                                            <flux:button variant="danger" size="sm" icon="check" wire:click="deleteFilter({{ $filter->id }})" />
                                                            <flux:button variant="ghost" size="sm" wire:click="$set('confirmingDeleteFilter', null)">
                                                                {{ __('Cancel') }}
                                                            </flux:button>
                                                        @else
                                                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="$set('confirmingDeleteFilter', {{ $filter->id }})" />
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</section>
