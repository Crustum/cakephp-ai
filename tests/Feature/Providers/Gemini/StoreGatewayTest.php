<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Stores;
use Crustum\Ai\Support\Sleeper;

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
            aiHttpResponse([
                'name' => 'fileSearchStores/store123/operations/import1',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/store123/documents/doc1'],
            ]),
            aiHttpResponse([
                'name' => 'fileSearchStores/store123/operations/import2',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/store123/documents/doc2'],
            ]),
        ]),
    ]);

    Stores::create('Test Store', fileIds: ['files/file1', 'files/file2'], provider: 'gemini');

    aiAssertHttpSentCount(4);
});

test('add file sends correct request', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => true,
            'response' => [
                'documentName' => 'fileSearchStores/store123/documents/doc456',
            ],
        ]),
    ]);

    $provider = geminiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

    expect($documentId)->toBe('doc456');

    aiAssertHttpSent(fn($request): bool => $request->method() === 'POST'
        && str_contains((string)$request->url(), 'fileSearchStores/store123:importFile')
        && ($request->data()['fileName'] ?? null) === 'files/file789');
});

test('add file waits for the import operation and returns the document id', function (): void {
    Sleeper::fake();

    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpSequence([
            aiHttpResponse([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => false,
            ]),
            aiHttpResponse([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'response' => [
                    'documentName' => 'fileSearchStores/store123/documents/doc456',
                ],
            ]),
        ]),
    ]);

    $provider = geminiProvider();
    $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

    expect($documentId)->toBe('doc456');

    aiAssertHttpSentCount(2);
    aiAssertHttpSent(fn($request): bool => $request->method() === 'GET'
        && str_contains((string)$request->url(), 'fileSearchStores/store123/operations/import456'));
    expect(Sleeper::sequence())->toBe([5_000_000]);
});

test('add file reports an import operation error', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => true,
            'error' => [
                'code' => 13,
                'message' => 'Indexing failed.',
            ],
        ]),
    ]);

    $provider = geminiProvider();

    expect(fn(): string => $provider->storeGateway()->addFile($provider, 'store123', 'file789'))
        ->toThrow(AiException::class, 'Gemini Error: [13] Indexing failed.');
});

test('add file rejects a completed import without a document name', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => true,
            'response' => [],
        ]),
    ]);

    $provider = geminiProvider();

    expect(fn(): string => $provider->storeGateway()->addFile($provider, 'store123', 'file789'))
        ->toThrow(AiException::class, 'Gemini Error: [invalid_response] File import completed without a document name.');
});

test('add file times out when the import operation never completes', function (): void {
    Sleeper::fake();

    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => false,
        ]),
    ]);

    $provider = geminiProvider();

    expect(fn(): string => $provider->storeGateway()->addFile($provider, 'store123', 'file789'))
        ->toThrow(AiException::class, 'Gemini Error: [timeout] File import operation did not complete.');

    aiAssertHttpSentCount(61);
});

test('add file with metadata formats correctly', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => true,
            'response' => [
                'documentName' => 'fileSearchStores/store123/documents/doc456',
            ],
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

test('removing a document deletes the file it was imported from, not the document', function (): void {
    aiHttpFake([
        '*:importFile' => aiHttpResponse([
            'name' => 'fileSearchStores/store123/operations/import456',
            'done' => true,
            'response' => ['documentName' => 'fileSearchStores/store123/documents/file789-abc123'],
        ]),
        '*/fileSearchStores/store123' => aiHttpResponse(fakeStoreResponse()),
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $store = Stores::get('store123', provider: 'gemini');

    $document = $store->add('files/file789');

    expect($document->id())->toBe('file789-abc123')
        ->and($document->fileId())->toBe('files/file789');

    expect($store->remove($document, deleteFile: true))->toBeTrue();

    $deletes = [];

    foreach (aiHttpRecorded() as $pair) {
        if ($pair[0]->method() === 'DELETE') {
            $deletes[] = (string)$pair[0]->url();
        }
    }

    $deletes = array_values($deletes);

    // Gemini mints a document ID of its own, so deleting files/{document} would 403.
    expect($deletes)->toHaveCount(2)
        ->and($deletes[0])->toContain('fileSearchStores/store123/documents/file789-abc123')
        ->and($deletes[1])->toEndWith('/files/file789')
        ->and($deletes[1])->not->toContain('file789-abc123');
});

test('deleting a store forces removal so a non-empty store still deletes', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();

    expect($provider->storeGateway()->deleteStore($provider, 'store123'))->toBeTrue();

    // Gemini rejects deleting a non-empty store unless force is a query parameter.
    expect(sentRequest()->url())->toEndWith('/fileSearchStores/store123?force=true')
        ->and(sentRequest()->method())->toBe('DELETE');
});

test('removing a document sends force as a query parameter', function (): void {
    aiHttpFake([
        'generativelanguage.googleapis.com/*' => aiHttpResponse([], 200),
    ]);

    $provider = geminiProvider();

    $provider->storeGateway()->removeFile($provider, 'store123', 'doc456');

    expect(sentRequest()->url())->toEndWith('/fileSearchStores/store123/documents/doc456?force=true')
        ->and(sentRequest()->body())->toBe('');
});
