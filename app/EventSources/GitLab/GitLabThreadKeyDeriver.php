<?php

namespace App\EventSources\GitLab;

use App\Contracts\ThreadKeyDeriver;
use Illuminate\Support\Arr;

class GitLabThreadKeyDeriver implements ThreadKeyDeriver
{
    public function deriveKey(string $eventType, array $payload): ?string
    {
        // Use normalized payload structure (repository.full_name, pull_request/issue)
        $repo = Arr::get($payload, 'repository.full_name')
            ?? Arr::get($payload, 'project.path_with_namespace');

        if (! $repo) {
            return null;
        }

        // Check normalized pull_request (from merge request)
        if ($number = Arr::get($payload, 'pull_request.number')) {
            return "{$repo}:pr:{$number}";
        }

        // Check normalized issue
        if ($number = Arr::get($payload, 'issue.number')) {
            return "{$repo}:issue:{$number}";
        }

        // Check raw GitLab merge request
        if ($iid = Arr::get($payload, 'object_attributes.iid')) {
            if (Arr::has($payload, 'object_attributes.source_branch')) {
                return "{$repo}:pr:{$iid}";
            }

            return "{$repo}:issue:{$iid}";
        }

        // Check note events
        if ($iid = Arr::get($payload, 'merge_request.iid')) {
            return "{$repo}:pr:{$iid}";
        }

        if ($iid = Arr::get($payload, 'issue.iid')) {
            return "{$repo}:issue:{$iid}";
        }

        return null;
    }
}
