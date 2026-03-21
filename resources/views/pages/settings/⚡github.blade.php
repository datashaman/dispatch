<?php

use App\Models\GitHubInstallation;
use App\Services\GitHubAppService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GitHub App')] class extends Component {
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
    public function installUrl(): ?string
    {
        return app(GitHubAppService::class)->getInstallUrl();
    }

    #[Computed]
    public function installations(): \Illuminate\Database\Eloquent\Collection
    {
        return GitHubInstallation::orderBy('account_login')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('GitHub App')" :subheading="__('Connect a GitHub App to receive webhooks from your repositories.')">
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

            @if ($this->isConfigured && $this->installUrl)
                <flux:button variant="primary" icon="plus" href="{{ $this->installUrl }}" target="_blank">
                    {{ __('Install on GitHub') }}
                </flux:button>
            @endif

            {{-- Installations --}}
            @if ($this->installations->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('Installations') }}</flux:heading>
                    @foreach ($this->installations as $installation)
                        <a href="{{ route('github.repos', $installation) }}" wire:navigate
                           class="block rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div class="flex items-center justify-between">
                                <div>
                                    <flux:heading size="sm">{{ $installation->account_login }}</flux:heading>
                                    <flux:text class="mt-0.5 text-xs">
                                        {{ $installation->account_type }} &middot;
                                        {{ $installation->projects()->count() }} {{ __('registered repos') }}
                                    </flux:text>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($installation->suspended_at)
                                        <flux:badge color="amber" size="sm">{{ __('Suspended') }}</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                    @endif
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Flash messages --}}
            @if (session('status'))
                <flux:callout variant="success">{{ session('status') }}</flux:callout>
            @endif
            @if (session('error'))
                <flux:callout variant="danger">{{ session('error') }}</flux:callout>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
