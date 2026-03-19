<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class WorktreeManager
{
    /**
     * Create a temporary git worktree for isolated agent execution.
     *
     * @return array{path: string, branch: string}
     */
    public function create(string $projectPath, string $ruleId): array
    {
        $safeRuleId = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $ruleId);
        $shortHash = Str::random(8);
        $branch = "dispatch/{$safeRuleId}/{$shortHash}";
        $worktreeBase = rtrim($projectPath, '/').'/.worktrees';
        $worktreePath = $worktreeBase.'/'.$safeRuleId.'-'.$shortHash;

        File::ensureDirectoryExists($worktreeBase);

        $result = Process::path($projectPath)
            ->run(['git', 'worktree', 'add', '-b', $branch, $worktreePath]);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Failed to create git worktree: {$result->errorOutput()}"
            );
        }

        return [
            'path' => $worktreePath,
            'branch' => $branch,
        ];
    }

    /**
     * Check if any new commits were made in the worktree since it was created.
     */
    public function hasNewCommits(string $worktreePath, string $projectPath): bool
    {
        // Get the HEAD of the main repo
        $mainHead = Process::path($projectPath)
            ->run(['git', 'rev-parse', 'HEAD']);

        // Get the HEAD of the worktree
        $worktreeHead = Process::path($worktreePath)
            ->run(['git', 'rev-parse', 'HEAD']);

        if (! $mainHead->successful() || ! $worktreeHead->successful()) {
            return false;
        }

        return trim($mainHead->output()) !== trim($worktreeHead->output());
    }

    /**
     * Remove a worktree and its branch.
     */
    public function remove(string $worktreePath, string $branch, string $projectPath): void
    {
        // Remove the worktree
        Process::path($projectPath)
            ->run(['git', 'worktree', 'remove', $worktreePath, '--force']);

        // Delete the branch
        Process::path($projectPath)
            ->run(['git', 'branch', '-D', $branch]);
    }

    /**
     * Get the unified diff of all changes in a worktree relative to the main repo HEAD.
     */
    public function getDiff(string $worktreePath, string $projectPath): ?string
    {
        $mainHead = Process::path($projectPath)
            ->run(['git', 'rev-parse', 'HEAD']);

        if (! $mainHead->successful()) {
            return null;
        }

        $baseCommit = trim($mainHead->output());

        $result = Process::path($worktreePath)
            ->run(['git', 'diff', $baseCommit.'...HEAD']);

        if (! $result->successful()) {
            return null;
        }

        $diff = trim($result->output());

        return $diff !== '' ? $diff : null;
    }

    /**
     * Clean up a worktree after execution.
     * Removes it if no new commits were made, leaves it in place otherwise.
     *
     * @return bool True if the worktree was removed, false if retained.
     */
    public function cleanup(string $worktreePath, string $branch, string $projectPath): bool
    {
        if ($this->hasNewCommits($worktreePath, $projectPath)) {
            return false;
        }

        $this->remove($worktreePath, $branch, $projectPath);

        return true;
    }
}
