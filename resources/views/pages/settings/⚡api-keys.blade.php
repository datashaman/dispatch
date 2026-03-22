<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API Keys & Defaults')] class extends Component {
    // Per-provider API keys
    public array $providerKeys = [];

    // Default provider and model
    public string $defaultProvider = '';
    public string $defaultModel = '';

    // UI state
    public string $statusMessage = '';

    public function mount(): void
    {
        $user = Auth::user();
        $keys = $user->api_keys ?? [];

        foreach ($this->providers as $providerId => $provider) {
            $this->providerKeys[$providerId] = '';
        }

        // Stitch key (not an AI provider, managed separately)
        $this->providerKeys['stitch'] = '';

        $this->defaultProvider = $user->default_provider ?? '';
        $this->defaultModel = $user->default_model ?? '';
    }

    #[Computed]
    public function providers(): array
    {
        return config('dispatch.providers', []);
    }

    #[Computed]
    public function storedKeys(): array
    {
        $keys = Auth::user()->api_keys ?? [];
        $result = [];

        foreach ($this->providers as $providerId => $provider) {
            $result[$providerId] = ! empty($keys[$providerId]);
        }

        // Stitch key (not an AI provider, managed separately)
        $result['stitch'] = ! empty($keys['stitch']);

        return $result;
    }

    #[Computed]
    public function configuredProviders(): array
    {
        return array_filter(
            $this->providers,
            fn ($provider, $providerId) => ! empty($this->storedKeys[$providerId]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    #[Computed]
    public function modelsForProvider(): array
    {
        if (! $this->defaultProvider) {
            return [];
        }

        return $this->providers[$this->defaultProvider]['models'] ?? [];
    }

    public function saveKey(string $provider): void
    {
        $key = $this->providerKeys[$provider] ?? '';

        if (empty($key)) {
            return;
        }

        $user = Auth::user();
        $keys = $user->api_keys ?? [];
        $keys[$provider] = $key;
        $user->update(['api_keys' => $keys]);

        $this->providerKeys[$provider] = '';
        $label = $this->providers[$provider]['label'] ?? ucfirst($provider);
        $this->statusMessage = $label . ' key saved.';
        unset($this->storedKeys, $this->configuredProviders);
    }

    public function clearKey(string $provider): void
    {
        $user = Auth::user();
        $keys = $user->api_keys ?? [];
        unset($keys[$provider]);
        $user->update(['api_keys' => empty($keys) ? null : $keys]);

        $label = $this->providers[$provider]['label'] ?? ucfirst($provider);
        $this->statusMessage = $label . ' key cleared.';
        unset($this->storedKeys, $this->configuredProviders);

        // Reset default provider if the cleared key was the selected default
        if ($this->defaultProvider === $provider) {
            $this->defaultProvider = '';
            $this->defaultModel = '';
            $user->update(['default_provider' => null, 'default_model' => null]);
            unset($this->modelsForProvider);
        }
    }

    public function saveDefaults(): void
    {
        $user = Auth::user();

        // Reset model if provider changed and model doesn't belong to new provider
        if ($this->defaultProvider && $this->defaultModel) {
            if (! in_array($this->defaultModel, ['cheapest', 'smartest'], true)) {
                $validModels = array_keys($this->providers[$this->defaultProvider]['models'] ?? []);
                if (! in_array($this->defaultModel, $validModels, true)) {
                    $this->defaultModel = '';
                }
            }
        }

        $user->update([
            'default_provider' => $this->defaultProvider ?: null,
            'default_model' => $this->defaultModel ?: null,
        ]);

        $this->statusMessage = 'Defaults saved.';
    }

    public function updatedDefaultProvider(): void
    {
        $this->defaultModel = '';
        unset($this->modelsForProvider);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('API Keys & Defaults')" :subheading="__('Manage your default API keys and preferred provider/model.')">
        @if ($statusMessage)
            <div class="mb-4">
                <flux:badge color="green">{{ $statusMessage }}</flux:badge>
            </div>
        @endif

        {{-- Default Provider & Model --}}
        <div class="my-6 space-y-4">
            <flux:heading size="sm">{{ __('Default Provider & Model') }}</flux:heading>
            <flux:subheading>{{ __('Used when a project or rule doesn\'t specify its own.') }}</flux:subheading>

            <div class="space-y-3 mt-3">
                <flux:select wire:model.live="defaultProvider" :label="__('Provider')" placeholder="Select a provider...">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->configuredProviders as $providerId => $provider)
                        <flux:select.option value="{{ $providerId }}">{{ $provider['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($defaultProvider && ! empty($this->modelsForProvider))
                    <flux:select wire:model="defaultModel" :label="__('Model')">
                        <flux:select.option value="">{{ __('Provider default') }}</flux:select.option>
                        <flux:select.option value="cheapest">{{ __('Cheapest (auto)') }}</flux:select.option>
                        <flux:select.option value="smartest">{{ __('Smartest (auto)') }}</flux:select.option>
                        @foreach ($this->modelsForProvider as $modelId => $modelName)
                            <flux:select.option value="{{ $modelId }}">{{ $modelName }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:button variant="primary" size="sm" wire:click="saveDefaults">
                    {{ __('Save Defaults') }}
                </flux:button>
            </div>
        </div>

        <flux:separator />

        {{-- Per-Provider API Keys --}}
        <div class="my-6 space-y-4">
            <flux:heading size="sm">{{ __('AI Provider Keys') }}</flux:heading>
            <flux:subheading>{{ __('Enter your API keys for each provider. Keys are encrypted at rest. Projects can override these with their own keys.') }}</flux:subheading>

            <div class="space-y-5 mt-3">
                @foreach ($this->providers as $providerId => $provider)
                    <div class="space-y-1">
                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ $provider['label'] }}
                        </div>

                        @if ($this->storedKeys[$providerId] ?? false)
                            <div class="flex items-center gap-2">
                                <flux:badge color="green" size="sm">{{ __('Configured') }}</flux:badge>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="clearKey('{{ $providerId }}')"
                                    wire:confirm="{{ __('Remove this API key?') }}"
                                >
                                    {{ __('Clear') }}
                                </flux:button>
                            </div>
                        @else
                            <div class="flex items-center gap-2">
                                <flux:input
                                    type="password"
                                    wire:model="providerKeys.{{ $providerId }}"
                                    placeholder="{{ __('Enter API key...') }}"
                                    size="sm"
                                    class="flex-1"
                                />
                                <flux:button size="sm" variant="primary" wire:click="saveKey('{{ $providerId }}')">
                                    {{ __('Save') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <flux:separator />

        {{-- Stitch API Key --}}
        <div class="my-6 space-y-4">
            <flux:heading size="sm">{{ __('Stitch (Google UI Generation)') }}</flux:heading>
            <flux:subheading>{{ __('API key for Google Stitch UI generation. Get one from stitch.withgoogle.com. Used by StitchGenerate and StitchEdit agent tools.') }}</flux:subheading>

            <div class="space-y-1 mt-3">
                @if ($this->storedKeys['stitch'] ?? false)
                    <div class="flex items-center gap-2">
                        <flux:badge color="green" size="sm">{{ __('Configured') }}</flux:badge>
                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="clearKey('stitch')"
                            wire:confirm="{{ __('Remove Stitch API key?') }}"
                        >
                            {{ __('Clear') }}
                        </flux:button>
                    </div>
                @else
                    <div class="flex items-center gap-2">
                        <flux:input
                            type="password"
                            wire:model="providerKeys.stitch"
                            placeholder="{{ __('Enter Stitch API key...') }}"
                            size="sm"
                            class="flex-1"
                        />
                        <flux:button size="sm" variant="primary" wire:click="saveKey('stitch')">
                            {{ __('Save') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    </x-pages::settings.layout>
</section>
