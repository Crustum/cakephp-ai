<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]);
});

test('put file uploads to the v1 endpoint with the assistants purpose', function (): void {
    aiHttpFake([
        'test-resource.openai.azure.com/*' => aiHttpResponse(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'azure',
    );

    expect($response->id)->toBe('file-uploaded123');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/files'
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
        && multipartField($request, 'purpose') === 'assistants'
        && $request->hasHeader('api-key', 'test-key'));
});

test('provider options are resolved with the azure key, not openai', function (): void {
    aiHttpFake([
        'test-resource.openai.azure.com/*' => aiHttpResponse(['id' => 'file-uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(fn(Lab $provider): array => match ($provider) {
            Lab::Azure => ['purpose' => 'batch'],
            Lab::OpenAI => ['purpose' => 'vision'],
            default => [],
        })
        ->put(provider: 'azure');

    expect(multipartField(sentRequest(), 'purpose'))->toBe('batch');
});
