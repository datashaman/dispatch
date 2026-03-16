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
     * Resolve tool names to Tool instances.
     *
     * If $tools is empty, all available tools are resolved.
     *
     * @param  list<string>  $tools  Explicit list of tools to resolve (empty = all)
     * @return list<Tool>
     */
    public function resolve(array $tools, string $workingDirectory): array
    {
        $toolNames = ! empty($tools) ? $tools : array_keys(static::$tools);

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
