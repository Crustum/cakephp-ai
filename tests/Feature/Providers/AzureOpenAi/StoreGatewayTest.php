<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Stores;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://test-resource.openai.azure.com',
    ]);
});

function fakeAzureStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
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

test('get store sends request to the v1 endpoint with the api-key header', function (): void {
    aiHttpFake([
        'test-resource.openai.azure.com/*' => aiHttpResponse(fakeAzureStoreResponse()),
    ]);

    expect(Stores::get('vs-123', provider: 'azure')->id)->toBe('vs-123');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
        && $request->hasHeader('api-key', 'test-key'));
});

test('create store sends request to the v1 endpoint with the api-key header', function (): void {
    aiHttpFake([
        'test-resource.openai.azure.com/*' => aiHttpResponse(fakeAzureStoreResponse()),
    ]);

    expect(Stores::create('Test Store', provider: 'azure')->id)->toBe('vs-123');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores'
        && $request->hasHeader('api-key', 'test-key'));
});

test('file search metadata filters throw an exception', function (): void {
    $search = new FileSearch(['vs-123'], where: ['company' => 'cakephp']);

    expect(fn() => Ai::getManager()->storeProvider('azure')->fileSearchToolOptions($search))
        ->toThrow(InvalidArgumentException::class, 'Azure OpenAI does not support file search metadata filters.');
});

test('file search without filters returns vector store ids', function (): void {
    $search = new FileSearch(['vs-123']);

    expect(Ai::getManager()->storeProvider('azure')->fileSearchToolOptions($search))
        ->toBe(['vector_store_ids' => ['vs-123']]);
});
