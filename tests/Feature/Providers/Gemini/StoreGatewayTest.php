<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Stores;

beforeEach(function (): void {
    Configure::write('Ai.providers.gemini', [
        ...(array)Configure::read('Ai.providers.gemini'),
        'key' => 'test-gemini-key',
    ]);
});

function geminiProvider(): object
{
    return Ai::getManager()->provider('gemini');
}

function fakeStoreResponse(string $name = 'fileSearchStores/store123', string $displayName = 'Test Store'): array
{
    return [
        'name' => $name,
        'displayName' => $displayName,
        'activeDocumentsCount' => 5,
        'pendingDocumentsCount' => 1,
        'failedDocumentsCount' => 0,
    ];
}

test('get store sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse(fakeStoreResponse()),
    ]);

    $store = Stores::get('store123', provider: 'gemini');

    expect($store->id)->toBe('fileSearchStores/store123');
    expect($store->name)->toBe('Test Store');
    expect($store->fileCounts->completed)->toBe(5);
    expect($store->fileCounts->pending)->toBe(1);
    expect($store->fileCounts->failed)->toBe(0);
    expect($store->ready)->toBeTrue();

    aiAssertHttpSent(fn($request): bool => $request->method() === 'GET'
        && str_contains((string)$request->url(), 'v1beta/fileSearchStores/store123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('get store normalizes id with prefix', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse(fakeStoreResponse()),
    ]);

    Stores::get('fileSearchStores/store123', provider: 'gemini');

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'v1beta/fileSearchStores/store123')
        && ! str_contains((string)$request->url(), 'fileSearchStores/fileSearchStores/'));
});

test('create store sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse(fakeStoreResponse()),
    ]);

    $store = Stores::create('Test Store', provider: 'gemini');

    expect($store->id)->toBe('fileSearchStores/store123');
    expect($store->name)->toBe('Test Store');

    aiAssertHttpSent(fn($request): bool => $request->method() === 'POST'
        && str_contains((string)$request->url(), 'v1beta/fileSearchStores')
        && ($request->data()['displayName'] ?? null) === 'Test Store');
});

test('create store with file ids adds files', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse(['name' => 'fileSearchStores/store123']),
            aiHttpResponse(fakeStoreResponse()),
            aiHttpResponse(['name' => 'fileSearchStores/store123/documents/doc1']),
            aiHttpResponse(['name' => 'fileSearchStores/store123/documents/doc2']),
        ]),
    ]);

    Stores::create('Test Store', fileIds: ['files/file1', 'files/file2'], provider: 'gemini');

    aiAssertHttpSentCount(4);
});

test('add file sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/documents/doc456',
        ]),
    ]);

    $provider = geminiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

    expect($documentId)->toBe('doc456');

    aiAssertHttpSent(fn($request): bool => $request->method() === 'POST'
        && str_contains((string)$request->url(), 'fileSearchStores/store123:importFile')
        && ($request->data()['fileName'] ?? null) === 'files/file789');
});

test('add file with metadata formats correctly', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/documents/doc456',
        ]),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->addFile($provider, 'store123', 'file789', [
        'company' => 'cakephp',
        'priority' => 5,
        'tags' => ['php', 'framework'],
    ]);

    aiAssertHttpSent(function ($request): bool {
        $metadata = $request->data()['customMetadata'] ?? [];

        $string = collect($metadata)->filter(fn($m): bool => ($m['key'] ?? null) === 'company')->first();
        $numeric = collect($metadata)->filter(fn($m): bool => ($m['key'] ?? null) === 'priority')->first();
        $list = collect($metadata)->filter(fn($m): bool => ($m['key'] ?? null) === 'tags')->first();

        return $string['stringValue'] === 'cakephp'
            && $numeric['numericValue'] === 5
            && $list['stringListValue']['values'] === ['php', 'framework'];
    });
});

test('remove file sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();
    $result = $provider->storeGateway()->removeFile($provider, 'store123', 'doc456');

    expect($result)->toBeTrue();

    aiAssertHttpSent(fn($request): bool => $request->method() === 'DELETE'
        && str_contains((string)$request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file normalizes document id with files prefix', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'files/doc456');

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file normalizes document id with documents prefix', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'documents/doc456');

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'fileSearchStores/store123/documents/doc456'));
});

test('remove file accepts full document path', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();
    $provider->storeGateway()->removeFile($provider, 'store123', 'fileSearchStores/store123/documents/doc456');

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'fileSearchStores/store123/documents/doc456')
        && ! str_contains((string)$request->url(), 'fileSearchStores/store123/documents/fileSearchStores/'));
});

test('delete store sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $result = Stores::delete('store123', provider: 'gemini');

    expect($result)->toBeTrue();

    aiAssertHttpSent(fn($request): bool => $request->method() === 'DELETE'
        && str_contains((string)$request->url(), 'v1beta/fileSearchStores/store123')
        && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
});

test('store gateway uses custom base url', function (): void {
    Configure::write('Ai.providers.gemini', [

        ...(array)Configure::read('Ai.providers.gemini'),
        'key' => 'test-gemini-key',
        'url' => 'https://custom.api.example.com/v1beta',
    ]);

    aiHttpFake([
        'custom.api.example.com/*' => aiHttpResponse(fakeStoreResponse()),
    ]);

    Stores::get('store123', provider: 'gemini');

    aiAssertHttpSent(fn($request): bool => str_contains((string)$request->url(), 'custom.api.example.com/v1beta/fileSearchStores/store123'));
});
