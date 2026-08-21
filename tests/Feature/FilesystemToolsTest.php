<?php
declare(strict_types=1);

use Cake\Collection\Collection;
use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Agents\FileStorageAgent;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Test\Support\Storage\Storage;
use Crustum\Ai\Tools\FileStorage;
use Crustum\Ai\Tools\Filesystem\CopyFile;
use Crustum\Ai\Tools\Filesystem\DeleteFile;
use Crustum\Ai\Tools\Filesystem\FileExists;
use Crustum\Ai\Tools\Filesystem\GetFileMetadata;
use Crustum\Ai\Tools\Filesystem\GetFileUrl;
use Crustum\Ai\Tools\Filesystem\ListFiles;
use Crustum\Ai\Tools\Filesystem\ReadFile;
use Crustum\Ai\Tools\Filesystem\WriteFile;
use Crustum\Ai\Tools\Request;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;

beforeEach(function (): void {
    $this->fs = Storage::fake('local');
    Configure::write('Ai.providers.openai.key', 'test-key');
});

afterEach(function (): void {
    Storage::cleanup('local');
});

test('list files returns files and directories under a path', function (): void {
    Storage::filesystem()->put('docs/a.txt', 'A');
    Storage::filesystem()->put('docs/nested/b.txt', 'B');
    Storage::filesystem()->put('root.txt', 'R');

    $result = (new ListFiles($this->fs))->handle(new Request(['path' => 'docs']));

    expect($result)->toContain('docs/a.txt')
        ->toContain('docs/nested')
        ->not->toContain('root.txt');
});

test('list files can recurse into subdirectories', function (): void {
    Storage::filesystem()->put('docs/a.txt', 'A');
    Storage::filesystem()->put('docs/nested/b.txt', 'B');

    $result = (new ListFiles($this->fs))->handle(new Request(['path' => 'docs', 'recursive' => true]));

    expect($result)->toContain('docs/a.txt')->toContain('docs/nested/b.txt');
});

test('read file returns text contents', function (): void {
    Storage::filesystem()->put('note.txt', 'Hello world');

    $result = (new ReadFile($this->fs))->handle(new Request(['path' => 'note.txt']));

    expect($result)->toBe('Hello world');
});

test('read file reports a missing file', function (): void {
    $result = (new ReadFile($this->fs))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('read file rejects an oversized file', function (): void {
    Storage::filesystem()->put('big.txt', str_repeat('a', 300 * 1024));

    $result = (new ReadFile($this->fs))->handle(new Request(['path' => 'big.txt']));

    expect($result)->toContain('too large to read inline');
});

test('read file rejects a binary file', function (): void {
    Storage::filesystem()->put('image.bin', "\xff\xfe\x00\x01binary");

    $result = (new ReadFile($this->fs))->handle(new Request(['path' => 'image.bin']));

    expect($result)->toContain('appears to be binary');
});

test('file exists reports presence and absence', function (): void {
    Storage::filesystem()->put('there.txt', 'x');

    expect((new FileExists($this->fs))->handle(new Request(['path' => 'there.txt'])))
        ->toBe('File [there.txt] exists.');

    expect((new FileExists($this->fs))->handle(new Request(['path' => 'nope.txt'])))
        ->toBe('File [nope.txt] does not exist.');
});

test('file exists does not report directories as files', function (): void {
    Storage::filesystem()->makeDirectory('docs');

    $result = (new FileExists($this->fs))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
});

test('file metadata returns size and mime type', function (): void {
    Storage::filesystem()->put('data.txt', 'twelve bytes');

    $metadata = json_decode((new GetFileMetadata($this->fs))->handle(new Request(['path' => 'data.txt'])), true);

    expect($metadata['size'])->toBe(12)
        ->and($metadata)->toHaveKeys(['mime_type', 'last_modified', 'visibility']);
});

test('file metadata reports a missing file', function (): void {
    $result = (new GetFileMetadata($this->fs))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('file url returns a usable string and never throws', function (): void {
    Storage::filesystem()->put('pic.txt', 'x');

    expect((new GetFileUrl($this->fs))->handle(new Request(['path' => 'pic.txt'])))->toContain('pic.txt');

    expect((new GetFileUrl($this->fs))->handle(new Request(['path' => 'pic.txt', 'expires_in_minutes' => 5])))->toContain('pic.txt');
});

test('file url reports a missing file', function (): void {
    $result = (new GetFileUrl($this->fs))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('file url does not generate urls for directories', function (): void {
    Storage::filesystem()->makeDirectory('docs');

    $result = (new GetFileUrl($this->fs))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
});

test('write file creates a file', function (): void {
    $result = (new WriteFile($this->fs))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

    expect($result)->toContain('Wrote');
    Storage::filesystem()->assertExists('out.txt');
    expect(Storage::filesystem()->get('out.txt'))->toBe('written');
});

test('write file reports write failures', function (): void {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('write')->once()->with('out.txt', 'written')->andThrow(
        UnableToWriteFile::atLocation('out.txt'),
    );

    $result = (new WriteFile($filesystem))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

    expect($result)->toBe('Unable to write [out.txt].');
});

test('delete file removes a file', function (): void {
    Storage::filesystem()->put('gone.txt', 'x');

    $result = (new DeleteFile($this->fs))->handle(new Request(['path' => 'gone.txt']));

    expect($result)->toBe('Deleted [gone.txt].');
    Storage::filesystem()->assertMissing('gone.txt');
});

test('delete file reports a missing file', function (): void {
    $result = (new DeleteFile($this->fs))->handle(new Request(['path' => 'missing.txt']));

    expect($result)->toBe('File [missing.txt] does not exist.');
});

test('delete file does not report directories as files', function (): void {
    Storage::filesystem()->makeDirectory('docs');

    $result = (new DeleteFile($this->fs))->handle(new Request(['path' => 'docs']));

    expect($result)->toBe('File [docs] does not exist.');
    Storage::filesystem()->assertExists('docs');
});

test('delete file reports delete failures', function (): void {
    $filesystem = Mockery::mock(FilesystemOperator::class);
    $filesystem->shouldReceive('fileExists')->once()->with('gone.txt')->andReturnTrue();
    $filesystem->shouldReceive('delete')->once()->with('gone.txt')->andThrow(
        UnableToDeleteFile::atLocation('gone.txt'),
    );

    $result = (new DeleteFile($filesystem))->handle(new Request(['path' => 'gone.txt']));

    expect($result)->toBe('Unable to delete [gone.txt].');
});

test('copy file duplicates a file', function (): void {
    Storage::filesystem()->put('src.txt', 'data');

    $result = (new CopyFile($this->fs))->handle(new Request(['from' => 'src.txt', 'to' => 'dst.txt']));

    expect($result)->toBe('Copied [src.txt] to [dst.txt].');
    Storage::filesystem()->assertExists('dst.txt');
});

test('copy file reports a missing source', function (): void {
    $result = (new CopyFile($this->fs))->handle(new Request(['from' => 'missing.txt', 'to' => 'dst.txt']));

    expect($result)->toBe('Unable to copy [missing.txt] to [dst.txt]. The source file may not exist.');
});

test('file storage tools all returns every tool as a collection', function (): void {
    $tools = FileStorage::all($this->fs);

    expect($tools)->toBeInstanceOf(Collection::class)
        ->toHaveCount(8)
        ->and($tools->some(fn($tool): bool => $tool instanceof WriteFile))->toBeTrue();
});

test('file storage tools can be filtered as a collection', function (): void {
    $tools = FileStorage::all($this->fs)
        ->filter(fn($tool): bool => !($tool instanceof DeleteFile));

    expect($tools)->toHaveCount(7)
        ->and($tools->some(fn($tool): bool => $tool instanceof DeleteFile))->toBeFalse();
});

test('file storage tools readOnly returns only read tools', function (): void {
    $tools = FileStorage::readOnly($this->fs);

    expect($tools)->toBeInstanceOf(Collection::class)
        ->toHaveCount(5)
        ->and($tools->some(fn($tool): bool => $tool instanceof ReadFile))->toBeTrue()
        ->and($tools->some(fn($tool): bool => $tool instanceof WriteFile))->toBeFalse()
        ->and($tools->some(fn($tool): bool => $tool instanceof DeleteFile))->toBeFalse()
        ->and($tools->some(fn($tool): bool => $tool instanceof CopyFile))->toBeFalse();
});

test('filesystem tool names resolve to class basenames', function (): void {
    expect(ToolNameResolver::resolve(new ReadFile($this->fs)))->toBe('ReadFile')
        ->and(ToolNameResolver::resolve(new ListFiles($this->fs)))->toBe('ListFiles')
        ->and(ToolNameResolver::resolve(new WriteFile($this->fs)))->toBe('WriteFile');
});

test('filesystem tool schemas build', function (): void {
    $schema = (new CopyFile($this->fs))->schema(new JsonSchemaTypeFactory());

    expect($schema)->toHaveKeys(['from', 'to']);
});

test('every filesystem tool maps to a strict-compliant openai schema', function (): void {
    aiHttpFake(['*' => fakeOpenAiResponse('ok')]);

    agent(tools: FileStorage::all($this->fs))
        ->prompt('List the files', provider: 'openai');

    aiAssertHttpSent(function ($request): bool {
        $body = json_decode((string)$request->body(), true);
        $tools = collect(Hash::get($body, 'tools'))->filter(
            fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'function',
        );

        if ($tools->count() !== 8) {
            return false;
        }

        foreach ($tools as $tool) {
            $properties = array_keys($tool['parameters']['properties'] ?? []);

            if (
                ($tool['strict'] ?? false) !== true
                || array_diff($properties, $tool['parameters']['required'] ?? [])
                || ($tool['parameters']['additionalProperties'] ?? null) !== false
            ) {
                return false;
            }
        }

        return true;
    });
});

test('agent copies a file end to end', function (): void {
    Storage::filesystem()->putFileAs('photos', 'fake-image', 'photo1.jpg');

    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiFileToolCall('CopyFile', ['from' => 'photos/photo1.jpg', 'to' => 'wallpapers/photo1.jpg']),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new FileStorageAgent())->prompt('Copy photo1 into the wallpapers folder', provider: 'openai');

    expect(aiHttpRecorded())->toHaveCount(2);

    Storage::filesystem()->assertExists(['photos/photo1.jpg', 'wallpapers/photo1.jpg']);
    Storage::filesystem()->assertCount('wallpapers', 1);
});

test('agent deletes a file end to end', function (): void {
    Storage::filesystem()->putFileAs('photos', 'fake-image', 'photo1.jpg');
    Storage::filesystem()->assertExists('photos/photo1.jpg');

    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiFileToolCall('DeleteFile', ['path' => 'photos/photo1.jpg']),
            fakeOpenAiResponse('Deleted'),
        ]),
    ]);

    (new FileStorageAgent())->prompt('Delete photo1', provider: 'openai');

    Storage::filesystem()->assertMissing('photos/photo1.jpg');
    Storage::filesystem()->assertDirectoryEmpty('photos');
});

/**
 * Build a fake OpenAI tool call response for filesystem tools.
 *
 * @param string $name Tool name.
 * @param array<string, mixed> $arguments Tool arguments.
 * @return \Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition
 */
function fakeOpenAiFileToolCall(string $name, array $arguments): AiHttpResponseDefinition
{
    $id = uniqid();

    return aiHttpResponse([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_' . $id,
            'call_id' => 'call_' . $id,
            'name' => $name,
            'arguments' => json_encode($arguments),
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
