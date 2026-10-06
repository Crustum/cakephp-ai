<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('get file sends correct request and exposes the mime type', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse([
            'id' => 'or_file_abc123',
            'mime_type' => 'text/csv',
        ]),
    ]);

    $response = Files::get('or_file_abc123', provider: 'openrouter');

    expect($response->id)->toBe('or_file_abc123')
        ->and($response->mimeType())->toBe('text/csv');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('get file exposes a null mime type when the provider omits it', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['id' => 'or_file_abc123']),
    ]);

    expect(Files::get('or_file_abc123', provider: 'openrouter')->mimeType())->toBeNull();
});

test('put file sends the file as a multipart upload', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['id' => 'or_file_uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'openrouter',
    );

    expect($response->id)->toBe('or_file_uploaded123');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://openrouter.ai/api/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and($request->rawBody)->toContain('name="file"')
        ->and($request->rawBody)->toContain('filename="hello.txt"')
        ->and($request->rawBody)->toContain('Hello, World!')
        ->and($request->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue();
});

test('provider options are resolved with the openrouter key', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['id' => 'or_file_uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(fn(Lab $provider): array => match ($provider) {
            Lab::OpenRouter => ['workspace_id' => 'ws_123'],
            default => [],
        })
        ->put(provider: 'openrouter');

    expect(multipartField(sentRequest(), 'workspace_id'))->toBe('ws_123');
});

test('delete file sends correct request', function (): void {
    aiHttpFake([
        'openrouter.ai/*' => aiHttpResponse(['id' => 'or_file_abc123', 'deleted' => true]),
    ]);

    Files::delete('or_file_abc123', provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});
