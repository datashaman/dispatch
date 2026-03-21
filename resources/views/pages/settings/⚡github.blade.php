<?php

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
        </div>
    </x-pages::settings.layout>
</section>
