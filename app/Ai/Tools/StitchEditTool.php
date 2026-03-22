<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\HasProjectContext;
use App\Ai\Contracts\ProjectAware;
use App\Services\StitchBridge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class StitchEditTool implements ProjectAware, Tool
{
    use HasProjectContext;

    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Edit an existing Stitch UI screen design. Pass the screen_id from a previous StitchGenerate call and a prompt describing the changes.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->project) {
            return 'Error: No project context available for Stitch tool.';
        }

        $apiKey = $this->resolveApiKey();

        if (! $apiKey) {
            return 'Error: No Stitch API key configured. Add one in Settings > API Keys or in the project\'s API Keys section.';
        }

        $screenId = (string) $request->string('screen_id');
        $prompt = (string) $request->string('prompt');

        if ($screenId === '') {
            return 'Error: screen_id is required.';
        }

        if ($prompt === '') {
            return 'Error: prompt is required.';
        }

        try {
            $result = app(StitchBridge::class)->edit(
                screenId: $screenId,
                apiKey: $apiKey,
                prompt: $prompt,
            );

            return json_encode([
                'screen_id' => $result->screenId,
                'html' => $result->html,
                'screenshot_url' => $result->screenshotUrl,
            ], JSON_PRETTY_PRINT);
        } catch (RuntimeException $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'screen_id' => $schema->string()
                ->description('The Stitch screen ID to edit (from a previous StitchGenerate call).')
                ->required(),
            'prompt' => $schema->string()
                ->description('Description of the changes to make to the design.')
                ->required(),
        ];
    }
}
