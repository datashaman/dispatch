<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\CustomEventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomEventController extends Controller
{
    public function __construct(
        protected CustomEventDispatcher $dispatcher,
    ) {}

    /**
     * Receive a custom event from an external system or internal caller.
     * Bypasses webhook signature verification (internal events).
     */
    public function handle(Request $request): JsonResponse
    {
        $request->validate([
            'event_type' => 'required|string',
            'repo' => 'required|string',
            'payload' => 'sometimes|array',
        ]);

        $repo = $request->string('repo');
        $project = Project::where('repo', $repo)->first();

        if (! $project) {
            return response()->json([
                'ok' => false,
                'error' => "Project not found for repo '{$repo}'.",
            ], 404);
        }

        $eventDepth = (int) $request->input('_dispatch.event_depth', 0);

        $result = $this->dispatcher->dispatch(
            eventType: $request->string('event_type'),
            eventPayload: $request->input('payload', []),
            project: $project,
            eventDepth: $eventDepth,
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
