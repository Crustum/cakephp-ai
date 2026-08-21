<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Stores;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function openAiProvider(): object
{
    return Ai::getManager()->provider('openai');
}

function fakeOpenAiStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'completed',
        'file_counts' => [
            'completed' => 5,
            'in_progress' => 1,
            'failed' => 0,
        ],
    ];
}

test('get store sends correct request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(fakeOpenAiStoreResponse()),
    ]);

    $store = Stores::get('vs-123', provider: 'openai');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store')
        ->and($store->fileCounts->completed)->toBe(5)
        ->and($store->fileCounts->pending)->toBe(1)
        ->and($store->fileCounts->failed)->toBe(0)
        ->and($store->ready)->toBeTrue();

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('create store sends correct request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(fakeOpenAiStoreResponse()),
    ]);

    $store = Stores::create('Test Store', provider: 'openai');

    expect($store->id)->toBe('vs-123')
        ->and($store->name)->toBe('Test Store');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.openai.com/v1/vector_stores'
        && ($request->data()['name'] ?? null) === 'Test Store');
});

test('create store maps description to metadata', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Test Store', description: 'A test store', provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && ! array_key_exists('description', $request->data())
        && ($request->data()['metadata'] ?? null) === ['description' => 'A test store']);
});

test('create store includes file ids in request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Test Store', fileIds: ['file-1', 'file-2'], provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && ($request->data()['file_ids'] ?? null) === ['file-1', 'file-2']);
});

test('create store includes expiration when provided', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(fakeOpenAiStoreResponse()),
    ]);

    Stores::create('Expiring Store', expiresWhenIdleFor: days(7), provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        $expires = $request->data()['expires_after'] ?? [];

        return ($expires['anchor'] ?? null) === 'last_active_at'
            && ($expires['days'] ?? null) === 7;
    });
});

test('add file sends correct request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(['id' => 'doc-456']),
    ]);

    $provider = openAiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789');

    expect($documentId)->toBe('doc-456');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files'
        && ($request->data()['file_id'] ?? null) === 'file-789');
});

test('add file with metadata includes attributes', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(['id' => 'doc-456']),
    ]);

    $provider = openAiProvider();
    $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789', ['company' => 'cakephp']);

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => ($request->data()['attributes'] ?? null) === ['company' => 'cakephp']);
});

test('remove file sends correct request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(['deleted' => true]),
    ]);

    $provider = openAiProvider();
    $result = $provider->storeGateway()->removeFile($provider, 'vs-123', 'doc-456');

    expect($result)->toBeTrue();

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files/doc-456');
});

test('delete store sends correct request', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpResponse(['deleted' => true]),
    ]);

    $result = Stores::delete('vs-123', provider: 'openai');

    expect($result)->toBeTrue();

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});
