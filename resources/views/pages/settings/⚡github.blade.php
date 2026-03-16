<?php

use App\Models\GitHubInstallation;
use App\Services\GitHubAppService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GitHub App')] class extends Component {
    public string $statusMessage = '';
    public string $errorMessage = '';
    public string $manifestOrganization = '';
    public bool $confirmingDelete = false;

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

    public function deleteApp(): void
    {
        $this->statusMessage = '';
        $this->errorMessage = '';

        try {
            app(GitHubAppService::class)->deleteApp();
            $this->confirmingDelete = false;
            unset($this->isConfigured, $this->appInfo, $this->installations, $this->installUrl);
            $this->statusMessage = 'GitHub App deleted and credentials removed.';
        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to delete GitHub App: {$e->getMessage()}";
        }
    }

    #[Computed]
    public function manifest(): string
    {
        $service = app(GitHubAppService::class);

        return json_encode($service->buildManifest(config('app.url')));
    }

    #[Computed]
    public function manifestUrl(): string
    {
        $service = app(GitHubAppService::class);
        $org = trim($this->manifestOrganization);

        return $service->getManifestCreateUrl($org ?: null);
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

        {{-- Configuration Status --}}
        <div class="space-y-6">
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
                                {{ __('No GitHub App connected. Create one below or configure manually via .env.') }}
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

            @if (! $this->isConfigured)
                {{-- Manifest Flow: Create GitHub App --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
                    <flux:heading size="sm">{{ __('Create GitHub App') }}</flux:heading>
                    <flux:text>
                        {{ __('Automatically create and configure a GitHub App for this Dispatch instance. Your credentials will be saved to .env.') }}
                    </flux:text>

                    <flux:field>
                        <flux:label>{{ __('Organization (optional)') }}</flux:label>
                        <flux:input wire:model.live.debounce.300ms="manifestOrganization" placeholder="Leave empty for personal account" />
                        <flux:description>{{ __('Create the app under an organization instead of your personal account.') }}</flux:description>
                    </flux:field>

                    <form method="post" action="{{ $this->manifestUrl }}">
                        <input type="hidden" name="manifest" value="{{ $this->manifest }}">
                        <flux:button variant="primary" type="submit" icon="plus">
                            {{ __('Create GitHub App') }}
                        </flux:button>
                    </form>
                </div>
            @endif

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

                {{-- Danger Zone --}}
                <flux:separator />

                <div class="rounded-xl border border-red-200 dark:border-red-900/50 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">{{ __('Delete GitHub App') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Permanently deletes the app on GitHub and removes credentials from .env.') }}</flux:text>
                        </div>
                        <flux:button variant="danger" size="sm" icon="trash" wire:click="$set('confirmingDelete', true)">
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </div>

                <flux:modal wire:model="confirmingDelete">
                    <flux:heading size="lg">{{ __('Delete GitHub App?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('This will permanently delete the GitHub App and remove all credentials from your .env file. All installations will be disconnected. This cannot be undone.') }}
                    </flux:text>
                    <div class="mt-6 flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('confirmingDelete', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="danger" wire:click="deleteApp">{{ __('Delete GitHub App') }}</flux:button>
                    </div>
                </flux:modal>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
