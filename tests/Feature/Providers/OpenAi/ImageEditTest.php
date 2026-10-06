<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredImage;
use Crustum\Ai\Image;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;
use Crustum\Ai\Test\Support\Storage\LocalDisk;
use Crustum\Ai\Test\Support\TestFile;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function fakeOpenAiImageEditResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'data' => [[
            'b64_json' => base64_encode('edited-image'),
        ]],
    ]);
}

/**
 * Determine whether the request carries the expected multipart image file.
 *
 * Asserts on the raw multipart body because the fake HTTP layer records the
 * Guzzle-regenerated boundary in the headers while the body keeps its own.
 *
 * @param \Crustum\Ai\Test\Support\Http\AiHttpRequest $request Recorded request
 * @param string $field Multipart field name
 * @param string $content Expected file content
 * @param string $filename Expected file name
 */
function hasImageFile(AiHttpRequest $request, string $field, string $content, string $filename): bool
{
    $body = $request->rawBody;

    return str_contains($body, 'name="' . $field . '"')
        && str_contains($body, 'filename="' . $filename . '"')
        && str_contains($body, $content);
}

test('a base64 image is sent to the edits endpoint as the image content', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image[]', 'source-bytes', 'image.png'));
});

test('a remote image is fetched and sent as the image content', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('remote-bytes'),
        '*' => fakeOpenAiImageEditResponse(),
    ]);

    Image::of('Make it brighter')
        ->attachments([new RemoteImage('https://example.com/source.png', 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image[]', 'remote-bytes', 'image.png'));
});

test('a local image is sent as the image content', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai') . '.png';
    file_put_contents($path, 'local-bytes');

    Image::of('Make it brighter')
        ->attachments([new LocalImage($path, 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image[]', 'local-bytes', 'image.png'));

    unlink($path);
});

test('a stored image is sent as the image content', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    LocalDisk::put('images', 'source.png', 'stored-bytes');

    Image::of('Make it brighter')
        ->attachments([new StoredImage('source.png', 'images')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image[]', 'stored-bytes', 'image.png'));

    LocalDisk::cleanup('images');
});

test('an uploaded file is sent as the image content', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    $path = tempnam(sys_get_temp_dir(), 'ai') . '.png';
    file_put_contents($path, 'uploaded-bytes');

    Image::of('Make it brighter')
        ->attachments([TestFile::upload($path, 'source.png', 'image/png')])
        ->generate(provider: 'openai', model: 'gpt-image-1');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image[]', 'uploaded-bytes', 'image.png'));

    unlink($path);
});

test('a provider image cannot be used for edits', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new ProviderImage('file-123')])
        ->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(InvalidArgumentException::class, 'Unsupported image attachment type');

test('a document attachment cannot be used for edits', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new LocalDocument('/tmp/source.pdf', 'application/pdf')])
        ->generate(provider: 'openai', model: 'gpt-image-1');
})->throws(InvalidArgumentException::class, 'Unsupported image attachment type');

test('non gpt-image models send a single image field', function (): void {
    aiHttpFake(['*' => fakeOpenAiImageEditResponse()]);

    Image::of('Make it brighter')
        ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
        ->generate(provider: 'openai', model: 'dall-e-2');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => str_ends_with($request->url(), 'images/edits')
        && hasImageFile($request, 'image', 'source-bytes', 'image.png'));
});
