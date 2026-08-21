<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.anthropic', [

        ...(array)Configure::read('Ai.providers.anthropic'),
        'key' => 'test-key',
    ]);
});

test('get file sends correct request', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(['id' => 'file-abc123', 'mime_type' => 'text/plain']),
    ]);

    $response = Files::get('file-abc123', provider: 'anthropic');

    expect($response->id)->toBe('file-abc123');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});

test('put file sends multipart upload', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'anthropic',
    );

    expect($response->id)->toBe('file-uploaded123');

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.anthropic.com/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and($request->hasHeader('x-api-key', 'test-key'))->toBeTrue();
});

test('put file forwards provider options into the multipart upload', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(['id' => 'file-uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['custom_field' => 'value'])
        ->put(provider: 'anthropic');

    expect(multipartField(sentRequest(), 'custom_field'))->toBe('value');
});

test('delete file sends correct request', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(['id' => 'file-abc123']),
    ]);

    Files::delete('file-abc123', provider: 'anthropic');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});
