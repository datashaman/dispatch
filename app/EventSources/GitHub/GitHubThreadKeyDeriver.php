<?php

namespace App\EventSources\GitHub;

use App\Contracts\ThreadKeyDeriver;
use Illuminate\Support\Arr;

class GitHubThreadKeyDeriver implements ThreadKeyDeriver
{
    public function deriveKey(string $eventType, array $payload): ?string
    {
        $repo = Arr::get($payload, 'repository.full_name');

        if (! $repo) {
            return null;
        }

        if ($number = Arr::get($payload, 'pull_request.number')) {
            return "{$repo}:pr:{$number}";
        }

        if ($number = Arr::get($payload, 'issue.number')) {
            return "{$repo}:issue:{$number}";
        }

        if ($number = Arr::get($payload, 'discussion.number')) {
            return "{$repo}:discussion:{$number}";
        }

        return null;
    }
}
