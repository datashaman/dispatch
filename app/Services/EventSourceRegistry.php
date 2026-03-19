<?php

namespace App\Services;

use App\Contracts\EventSource;
use App\Contracts\OutputAdapter;
use App\Contracts\ThreadKeyDeriver;
use Illuminate\Http\Request;

class EventSourceRegistry
{
    /** @var array<string, array{source: EventSource|class-string<EventSource>, output: OutputAdapter|class-string<OutputAdapter>, threadKey: ThreadKeyDeriver|class-string<ThreadKeyDeriver>}> */
    protected array $sources = [];

    /**
     * Register an event source with its adapters (instances or class names for lazy resolution).
     */
    public function register(string $name, EventSource|string $source, OutputAdapter|string $output, ThreadKeyDeriver|string $threadKey): void
    {
        $this->sources[$name] = [
            'source' => $source,
            'output' => $output,
            'threadKey' => $threadKey,
        ];
    }

    /**
     * Auto-detect which source a request came from.
     */
    public function detect(Request $request): ?string
    {
        foreach ($this->sources as $name => $adapters) {
            $source = $this->resolve($adapters['source']);
            if ($source->validates($request)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Get the EventSource for a given source name.
     */
    public function source(string $name): EventSource
    {
        if (! isset($this->sources[$name])) {
            throw new \InvalidArgumentException("Unknown event source: {$name}");
        }

        return $this->resolve($this->sources[$name]['source']);
    }

    /**
     * Get the OutputAdapter for a given source name.
     */
    public function output(string $name): OutputAdapter
    {
        if (! isset($this->sources[$name])) {
            throw new \InvalidArgumentException("Unknown event source: {$name}");
        }

        return $this->resolve($this->sources[$name]['output']);
    }

    /**
     * Get the ThreadKeyDeriver for a given source name.
     */
    public function threadKey(string $name): ThreadKeyDeriver
    {
        if (! isset($this->sources[$name])) {
            throw new \InvalidArgumentException("Unknown event source: {$name}");
        }

        return $this->resolve($this->sources[$name]['threadKey']);
    }

    /**
     * Get all registered source names.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Resolve an instance from a class name or return it directly.
     */
    protected function resolve(object|string $target): object
    {
        if (is_string($target)) {
            return app($target);
        }

        return $target;
    }
}
