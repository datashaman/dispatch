<?php

use App\Models\GitHubInstallation;
use App\Services\GitHubAppService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GitHub App')] class extends Component {
    public string $statusMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        if (session('status')) {
            $this->statusMessage = session('status');
        }

        if (session('error')) {
            $this->errorMessage = session('error');
        }
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return app(GitHubAppService::class)->isConfigured();
    }

    #[Computed]
    public function appInfo(): ?array
    {
        if (! $this->isConfigured) {
            return null;
        }

        try {
            return app(GitHubAppService::class)->getApp();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function installations(): \Illuminate\Database\Eloquent\Collection
    {
        return GitHubInstallation::orderBy('account_login')->get();
    }

    #[Computed]
    public function installUrl(): ?string
    {
        return app(GitHubAppService::class)->getInstallUrl();
    }

    public function syncInstallations(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        try {
            $result = app(GitHubAppService::class)->syncInstallations();
            $this->statusMessage = "Synced: {$result['created']} added, {$result['updated']} updated, {$result['removed']} removed.";
            unset($this->installations);
        } catch (\Throwable $e) {
            $this->errorMessage = "Sync failed: {$e->getMessage()}";
        }
    }

    public function removeInstallation(int $id): void
    {
        GitHubInstallation::findOrFail($id)->delete();
        unset($this->installations);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('GitHub App')" :subheading="__('Connect a GitHub App to browse and auto-register repositories.')">
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

        <div class="space-y-6">
            {{-- App Status --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="sm">{{ __('App Status') }}</flux:heading>
                        @if ($this->isConfigured && $this->appInfo)
                            <flux:text class="mt-1">
                                {{ __('Connected as') }} <strong>{{ $this->appInfo['name'] }}</strong>
                            </flux:text>
                        @elseif ($this->isConfigured)
                            <flux:text class="mt-1 text-amber-600 dark:text-amber-400">
                                {{ __('Configured but unable to connect. Check your private key.') }}
                            </flux:text>
                        @else
                            <flux:text class="mt-1 text-zinc-500">
                                {{ __('No GitHub App connected. Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY in your environment.') }}
                            </flux:text>
                        @endif
                    </div>
                    <div>
                        @if ($this->isConfigured && $this->appInfo)
                            <flux:badge color="green">{{ __('Connected') }}</flux:badge>
                        @elseif ($this->isConfigured)
                            <flux:badge color="amber">{{ __('Error') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('Not Configured') }}</flux:badge>
                        @endif
                    </div>
                </div>
            </div>

            @if ($this->isConfigured)
                {{-- Actions --}}
                <div class="flex gap-2">
                    <flux:button variant="primary" icon="arrow-path" wire:click="syncInstallations" wire:loading.attr="disabled">
                        {{ __('Sync Installations') }}
                    </flux:button>
                    @if ($this->installUrl)
                        <flux:button variant="ghost" icon="plus" href="{{ $this->installUrl }}" target="_blank">
                            {{ __('Install on GitHub') }}
                        </flux:button>
                    @endif
                </div>

                {{-- Installations --}}
                @if ($this->installations->isNotEmpty())
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('Installations') }}</flux:heading>
                        @foreach ($this->installations as $installation)
                            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4" wire:key="installation-{{ $installation->id }}">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <flux:heading size="sm">{{ $installation->account_login }}</flux:heading>
                                        <flux:text class="mt-1 text-xs">
                                            {{ $installation->account_type }} &middot; ID: {{ $installation->installation_id }}
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <flux:button variant="primary" size="sm" icon="folder-plus" :href="route('github.repos', $installation)" wire:navigate>
                                            {{ __('Browse Repos') }}
                                        </flux:button>
                                        @if ($installation->suspended_at)
                                            <flux:badge color="amber" size="sm">{{ __('Suspended') }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <flux:text class="text-zinc-500">
                        {{ __('No installations found. Click "Install on GitHub" or "Sync Installations" to get started.') }}
                    </flux:text>
                @endif
            @endif
        </div>
    </x-pages::settings.layout>
</section>
