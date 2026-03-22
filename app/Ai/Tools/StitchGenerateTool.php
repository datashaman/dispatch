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

class StitchGenerateTool implements ProjectAware, Tool
{
    use HasProjectContext;

    public function __construct(
        protected string $workingDirectory,
    ) {}

    public function description(): Stringable|string
    {
        return 'Generate a UI screen design using Google Stitch. Returns HTML content and a screenshot URL. The project and API key are resolved automatically.';
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

        $prompt = (string) $request->string('prompt');

        if ($prompt === '') {
            return 'Error: prompt is required.';
        }

        try {
            $result = app(StitchBridge::class)->generate(
                project: $this->project,
                apiKey: $apiKey,
                prompt: $prompt,
                device: (string) ($request->string('device', 'DESKTOP') ?: 'DESKTOP'),
                vibe: ((string) $request->string('vibe')) ?: null,
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
            'prompt' => $schema->string()
                ->description('The design prompt describing what UI to generate.')
                ->required(),
            'device' => $schema->string()
                ->description('Device type: DESKTOP (default), MOBILE, TABLET, or AGNOSTIC.'),
            'vibe' => $schema->string()
                ->description('Design system context (e.g. contents of DESIGN.md) to match existing visual style.'),
        ];
    }
}
