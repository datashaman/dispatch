<?php

namespace App\Providers;

use App\Services\EventSourceRegistry;
use Illuminate\Support\ServiceProvider;

class EventSourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventSourceRegistry::class, function () {
            return new EventSourceRegistry;
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(EventSourceRegistry::class);

        foreach (config('dispatch.sources', []) as $name => $config) {
            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            // Register class names for lazy resolution — adapters are
            // resolved from the container on each access, ensuring
            // mocks and test overrides take effect.
            $registry->register(
                $name,
                $config['source'],
                $config['output'],
                $config['thread_key'],
            );
        }
    }
}
