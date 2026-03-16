<?php

use App\Ai\Tools\BashTool;
use App\Ai\Tools\EditTool;
use App\Ai\Tools\GlobTool;
use App\Ai\Tools\GrepTool;
use App\Ai\Tools\ReadTool;
use App\Ai\Tools\WriteTool;
use App\Services\ToolRegistry;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->workDir = sys_get_temp_dir().'/dispatch-tools-test-'.uniqid();
    File::makeDirectory($this->workDir, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->workDir);
});

// --- ReadTool ---

test('ReadTool reads file contents', function () {
    File::put($this->workDir.'/test.txt', 'Hello, world!');

    $tool = new ReadTool($this->workDir);
    $request = new Request(['path' => 'test.txt']);

    $result = $tool->handle($request);

    expect($result)->toBe('Hello, world!');
});

test('ReadTool returns error for missing file', function () {
    $tool = new ReadTool($this->workDir);
    $request = new Request(['path' => 'missing.txt']);

    $result = $tool->handle($request);

    expect($result)->toContain('Error: File not found');
});

test('ReadTool prevents path traversal', function () {
    $tool = new ReadTool($this->workDir);
    $request = new Request(['path' => '../../etc/passwd']);

    $result = $tool->handle($request);

    expect($result)->toContain('Error:');
});

test('ReadTool implements Tool interface', function () {
    $tool = new ReadTool($this->workDir);
    expect($tool)->toBeInstanceOf(Tool::class);
});

// --- EditTool ---

test('EditTool replaces string in file', function () {
    File::put($this->workDir.'/test.txt', 'Hello, world!');

    $tool = new EditTool($this->workDir);
    $request = new Request([
        'path' => 'test.txt',
        'old_string' => 'world',
        'new_string' => 'Laravel',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Successfully edited')
        ->and(File::get($this->workDir.'/test.txt'))->toBe('Hello, Laravel!');
});

test('EditTool returns error when old_string not found', function () {
    File::put($this->workDir.'/test.txt', 'Hello, world!');

    $tool = new EditTool($this->workDir);
    $request = new Request([
        'path' => 'test.txt',
        'old_string' => 'nonexistent',
        'new_string' => 'replacement',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Error: old_string not found');
});

test('EditTool returns error for missing file', function () {
    $tool = new EditTool($this->workDir);
    $request = new Request([
        'path' => 'missing.txt',
        'old_string' => 'old',
        'new_string' => 'new',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Error: File not found');
});

// --- WriteTool ---

test('WriteTool creates new file', function () {
    $tool = new WriteTool($this->workDir);
    $request = new Request([
        'path' => 'new-file.txt',
        'content' => 'New content',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Successfully wrote')
        ->and(File::get($this->workDir.'/new-file.txt'))->toBe('New content');
});

test('WriteTool overwrites existing file', function () {
    File::put($this->workDir.'/existing.txt', 'Old content');

    $tool = new WriteTool($this->workDir);
    $request = new Request([
        'path' => 'existing.txt',
        'content' => 'New content',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Successfully wrote')
        ->and(File::get($this->workDir.'/existing.txt'))->toBe('New content');
});

test('WriteTool creates nested directories', function () {
    $tool = new WriteTool($this->workDir);
    $request = new Request([
        'path' => 'deep/nested/dir/file.txt',
        'content' => 'Nested content',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Successfully wrote')
        ->and(File::get($this->workDir.'/deep/nested/dir/file.txt'))->toBe('Nested content');
});

// --- BashTool ---

test('BashTool runs command and returns output', function () {
    $tool = new BashTool($this->workDir);
    $request = new Request(['command' => 'echo "hello from bash"']);

    $result = $tool->handle($request);

    expect($result)->toContain('hello from bash');
});

test('BashTool runs in working directory', function () {
    File::put($this->workDir.'/marker.txt', 'found');

    $tool = new BashTool($this->workDir);
    $request = new Request(['command' => 'cat marker.txt']);

    $result = $tool->handle($request);

    expect($result)->toContain('found');
});

test('BashTool returns error info for failed commands', function () {
    $tool = new BashTool($this->workDir);
    $request = new Request(['command' => 'exit 1']);

    $result = $tool->handle($request);

    expect($result)->toContain('Exit code: 1');
});

// --- GlobTool ---

test('GlobTool finds files by pattern', function () {
    File::put($this->workDir.'/file1.php', '<?php');
    File::put($this->workDir.'/file2.php', '<?php');
    File::put($this->workDir.'/file3.txt', 'text');

    $tool = new GlobTool($this->workDir);
    $request = new Request(['pattern' => '*.php']);

    $result = $tool->handle($request);

    expect($result)->toContain('file1.php')
        ->and($result)->toContain('file2.php')
        ->and($result)->not->toContain('file3.txt');
});

test('GlobTool returns message for no matches', function () {
    $tool = new GlobTool($this->workDir);
    $request = new Request(['pattern' => '*.xyz']);

    $result = $tool->handle($request);

    expect($result)->toContain('No files found');
});

// --- GrepTool ---

test('GrepTool searches file contents', function () {
    File::put($this->workDir.'/file1.php', "<?php\nclass Foo {}");
    File::put($this->workDir.'/file2.php', "<?php\nclass Bar {}");

    $tool = new GrepTool($this->workDir);
    $request = new Request(['pattern' => 'class Foo']);

    $result = $tool->handle($request);

    expect($result)->toContain('file1.php')
        ->and($result)->toContain('class Foo');
});

test('GrepTool filters by file include pattern', function () {
    File::put($this->workDir.'/code.php', 'function hello() {}');
    File::put($this->workDir.'/code.js', 'function hello() {}');

    $tool = new GrepTool($this->workDir);
    $request = new Request(['pattern' => 'function hello', 'include' => '*.php']);

    $result = $tool->handle($request);

    expect($result)->toContain('code.php')
        ->and($result)->not->toContain('code.js');
});

test('GrepTool returns message for no matches', function () {
    File::put($this->workDir.'/file.txt', 'nothing here');

    $tool = new GrepTool($this->workDir);
    $request = new Request(['pattern' => 'nonexistent_pattern_xyz']);

    $result = $tool->handle($request);

    expect($result)->toContain('No matches found');
});

// --- ToolRegistry ---

test('ToolRegistry resolves all tools when no allowed list specified', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve([], [], $this->workDir);

    expect($tools)->toHaveCount(6)
        ->and($tools[0])->toBeInstanceOf(ReadTool::class)
        ->and($tools[1])->toBeInstanceOf(EditTool::class)
        ->and($tools[2])->toBeInstanceOf(WriteTool::class)
        ->and($tools[3])->toBeInstanceOf(BashTool::class)
        ->and($tools[4])->toBeInstanceOf(GlobTool::class)
        ->and($tools[5])->toBeInstanceOf(GrepTool::class);
});

test('ToolRegistry resolves only specified tools', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve(['Read', 'Bash'], [], $this->workDir);

    expect($tools)->toHaveCount(2)
        ->and($tools[0])->toBeInstanceOf(ReadTool::class)
        ->and($tools[1])->toBeInstanceOf(BashTool::class);
});

test('ToolRegistry filters out disallowed tools', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve([], ['Bash', 'Write'], $this->workDir);

    expect($tools)->toHaveCount(4);

    $classes = array_map(fn ($t) => $t::class, $tools);
    expect($classes)->not->toContain(BashTool::class)
        ->and($classes)->not->toContain(WriteTool::class);
});

test('ToolRegistry disallowed takes precedence over allowed', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve(['Read', 'Bash', 'Write'], ['Bash'], $this->workDir);

    expect($tools)->toHaveCount(2);

    $classes = array_map(fn ($t) => $t::class, $tools);
    expect($classes)->toContain(ReadTool::class)
        ->and($classes)->toContain(WriteTool::class)
        ->and($classes)->not->toContain(BashTool::class);
});

test('ToolRegistry ignores unknown tool names', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve(['Read', 'UnknownTool'], [], $this->workDir);

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(ReadTool::class);
});

test('ToolRegistry returns available tool names', function () {
    $names = ToolRegistry::availableTools();

    expect($names)->toBe(['Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep']);
});

test('all tools implement Tool interface', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve([], [], $this->workDir);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});

test('all tools scope to working directory', function () {
    $registry = new ToolRegistry;
    $tools = $registry->resolve([], [], $this->workDir);

    foreach ($tools as $tool) {
        $reflection = new ReflectionProperty($tool, 'workingDirectory');
        expect($reflection->getValue($tool))->toBe($this->workDir);
    }
});
