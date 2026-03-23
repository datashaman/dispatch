<?php

namespace App\Services;

use App\DataTransferObjects\StitchResult;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class StitchBridge
{
    public function generate(Project $project, string $apiKey, string $prompt, string $device = 'DESKTOP', ?string $vibe = null): StitchResult
    {
        $projectId = $this->resolveProjectId($project, $apiKey);

        $args = ['generate', '--project', $projectId, '--device', $device];

        $stdin = ['prompt' => $prompt];

        if ($vibe) {
            $stdin['vibe'] = $vibe;
        }

        $data = $this->callBridge($args, $apiKey, $stdin);

        return new StitchResult(
            html: $data['html'] ?? '',
            screenshotUrl: $data['screenshot_url'] ?? '',
            screenId: $data['screen_id'] ?? '',
        );
    }

    public function edit(string $screenId, string $apiKey, string $prompt): StitchResult
    {
        $args = ['edit', '--screen', $screenId];

        $data = $this->callBridge($args, $apiKey, ['prompt' => $prompt]);

        return new StitchResult(
            html: $data['html'] ?? '',
            screenshotUrl: $data['screenshot_url'] ?? '',
            screenId: $data['screen_id'] ?? $screenId,
        );
    }

    /**
     * List all Stitch projects for the given API key.
     *
     * @return list<array{project_id: string, title: string}>
     */
    public function listProjects(string $apiKey): array
    {
        $data = $this->callBridge(['list-projects'], $apiKey);

        return $data['projects'] ?? [];
    }

    public function createProject(string $title, string $apiKey): string
    {
        $data = $this->callBridge(['create-project', '--title', $title], $apiKey);

        $projectId = $data['project_id'] ?? null;

        if (empty($projectId)) {
            throw new RuntimeException('Stitch returned empty project ID — creation may have failed');
        }

        return $projectId;
    }

    protected function resolveProjectId(Project $project, string $apiKey): string
    {
        if ($project->stitch_project_id) {
            return $project->stitch_project_id;
        }

        return DB::transaction(function () use ($project, $apiKey) {
            // Pessimistic lock to prevent duplicate Stitch project creation
            $locked = Project::where('id', $project->id)->lockForUpdate()->first();

            if ($locked->stitch_project_id) {
                return $locked->stitch_project_id;
            }

            $title = $locked->repo ?: "dispatch-project-{$locked->id}";

            Log::info('StitchBridge: creating Stitch project', [
                'project_id' => $locked->id,
                'title' => $title,
            ]);

            $stitchProjectId = $this->createProject($title, $apiKey);

            $locked->update(['stitch_project_id' => $stitchProjectId]);

            Log::info('StitchBridge: Stitch project created', [
                'project_id' => $locked->id,
                'stitch_project_id' => $stitchProjectId,
            ]);

            // Refresh the original model so callers see the new value
            $project->refresh();

            return $stitchProjectId;
        });
    }

    /**
     * Call the Node.js bridge process.
     *
     * Long values (prompt, vibe) are passed via stdin as JSON to avoid
     * OS argument length limits. Short values (project ID, device, screen ID)
     * remain as CLI args.
     *
     * @param  list<string>  $args
     * @param  array<string, string>|null  $stdin  Data to pass via stdin as JSON
     * @return array<string, mixed>
     */
    protected function callBridge(array $args, string $apiKey, ?array $stdin = null): array
    {
        $bridgePath = config('services.stitch.bridge_path');

        if (! $bridgePath || ! file_exists($bridgePath)) {
            throw new RuntimeException("Stitch bridge not found at: {$bridgePath}");
        }

        $command = array_merge(['node', $bridgePath], $args);

        $process = Process::env(['STITCH_API_KEY' => $apiKey])
            ->timeout(120);

        if ($stdin) {
            $process = $process->input(json_encode($stdin));
        }

        $result = $process->run($command);

        if (! $result->successful()) {
            $error = trim($result->errorOutput());
            throw new RuntimeException("Stitch bridge failed: {$error}");
        }

        $output = trim($result->output());

        if (empty($output)) {
            throw new RuntimeException('Stitch bridge returned empty output');
        }

        $data = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stitch bridge returned invalid JSON: '.json_last_error_msg());
        }

        return $data;
    }
}
