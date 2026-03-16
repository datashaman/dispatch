<?php

namespace App\Services;

use App\Ai\Tools\BashTool;
use App\Ai\Tools\EditTool;
use App\Ai\Tools\GlobTool;
use App\Ai\Tools\GrepTool;
use App\Ai\Tools\ReadTool;
use App\Ai\Tools\WriteTool;
use Laravel\Ai\Contracts\Tool;

class ToolRegistry
{
    /**
     * Map of tool names to their class implementations.
     *
     * @var array<string, class-string<Tool>>
     */
    protected static array $tools = [
        'Read' => ReadTool::class,
        'Edit' => EditTool::class,
        'Write' => WriteTool::class,
        'Bash' => BashTool::class,
        'Glob' => GlobTool::class,
        'Grep' => GrepTool::class,
    ];

    /**
     * Resolve tool names to Tool instances, filtering by allowed/disallowed lists.
     *
     * @param  list<string>  $allowedTools
     * @param  list<string>  $disallowedTools
     * @return list<Tool>
     */
    public function resolve(array $allowedTools, array $disallowedTools, string $workingDirectory): array
    {
        $toolNames = ! empty($allowedTools) ? $allowedTools : array_keys(static::$tools);

        $toolNames = array_diff($toolNames, $disallowedTools);

        $resolved = [];
        foreach ($toolNames as $name) {
            if (isset(static::$tools[$name])) {
                $resolved[] = new (static::$tools[$name])($workingDirectory);
            }
        }

        return $resolved;
    }

    /**
     * Get all available tool names.
     *
     * @return list<string>
     */
    public static function availableTools(): array
    {
        return array_keys(static::$tools);
    }
}
