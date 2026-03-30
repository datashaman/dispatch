<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\HasProjectContext;
use App\Ai\Contracts\ProjectAware;
use App\Services\CustomEventDispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class EmitCustomEventTool implements ProjectAware, Tool
{
    use HasProjectContext;

    protected int $eventDepth = 0;

    public function __construct(
        protected string $workingDirectory,
    ) {}

    /**
     * Set the current event depth for loop detection.
     */
    public function setEventDepth(int $depth): void
    {
        $this->eventDepth = $depth;
    }

    public function description(): Stringable|string
    {
        return 'Emit a custom event into the dispatch pipeline. The event will be matched against rules and may trigger other agents. Event types are automatically prefixed with "app." (e.g. "deploy_ready" becomes "app.deploy_ready").';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->project) {
            return 'Error: No project context available.';
        }

        $eventType = (string) $request->string('event_type');

        if ($eventType === '') {
            return 'Error: event_type is required.';
        }

        $payload = $request->array('payload') ?? [];

        $dispatcher = app(CustomEventDispatcher::class);

        $result = $dispatcher->dispatch(
            eventType: $eventType,
            eventPayload: $payload,
            project: $this->project,
            eventDepth: $this->eventDepth,
        );

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_type' => $schema->string()
                ->description('The custom event type (e.g. "analysis_complete", "deploy_ready"). Automatically prefixed with "app." if not already.')
                ->required(),
            'payload' => $schema->object()
                ->description('Optional arbitrary data to pass to matched rules.'),
        ];
    }
}
