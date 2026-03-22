<?php

namespace App\Ai\Concerns;

use App\Models\Project;

trait HasProjectContext
{
    protected ?Project $project = null;

    public function setProject(Project $project): void
    {
        $this->project = $project;
    }

    protected function resolveApiKey(): ?string
    {
        $secrets = $this->project->agent_secrets ?? [];

        if (! empty($secrets['stitch'])) {
            return $secrets['stitch'];
        }

        $userKeys = $this->project->user?->api_keys ?? [];

        if (! empty($userKeys['stitch'])) {
            return $userKeys['stitch'];
        }

        return null;
    }
}
