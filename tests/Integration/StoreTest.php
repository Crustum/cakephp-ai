<?php
declare(strict_types=1);

use Crustum\Ai\Event\CreatingStore;
use Crustum\Ai\Event\StoreCreated;
use Crustum\Ai\Event\StoreDeleted;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Providers\Tools\FileSearch;
use Crustum\Ai\Store;
use Crustum\Ai\Stores;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Test\Support\Skips\ProviderConfigured;

/**
 * Create a file search store with indexed fixture documents.
 *
 * @param string $provider Provider name.
 * @return array{0: \Crustum\Ai\Store, 1: array<int, string|null>}
 */
function createFileSearchStore(string $provider): array
{
    $store = Stores::create(
        'CakePHP AI Plugin Integration Test Store',
        provider: $provider,
    );

    $fileIds = [];

    $fileIds[] = $store->add(
        Document::fromPath(__DIR__ . '/../Fixtures/cakephp-roadmap.txt'),
        metadata: ['company' => 'cakephp'],
    )->fileId();

    $fileIds[] = $store->add(
        Document::fromPath(__DIR__ . '/../Fixtures/tailwind-roadmap.txt'),
        metadata: ['company' => 'tailwind'],
    )->fileId();

    $store = retry(60, function () use ($store): Store {
        $refreshed = $store->refresh();

        if ($refreshed->fileCounts->completed < 2) {
            throw new RuntimeException(sprintf('Store %s has only %d of 2 files indexed.', $refreshed->id, $refreshed->fileCounts->completed));
        }

        return $refreshed;
    }, 2000);

    return [$store, $fileIds];
}

test('can create get and delete store', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);
    ProviderConfigured::store($provider);

    $recorder = EventRecorder::start([
        CreatingStore::class,
        StoreCreated::class,
        StoreDeleted::class,
    ]);

    $created = Stores::create('Test Store', provider: $provider);

    expect($created->id)->not->toBeEmpty();

    $recorder->assertDispatched(CreatingStore::class);
    $recorder->assertDispatched(StoreCreated::class);

    $retrieved = Stores::get($created->id, provider: $provider);

    expect($retrieved->id)->toEqual($created->id)
        ->and($retrieved->name)->toEqual('Test Store')
        ->and($retrieved->fileCounts->completed)->toEqual(0)
        ->and($retrieved->ready)->toBeBool();

    $deleted = Stores::delete($created->id, provider: $provider);

    expect($deleted)->toBeTrue();

    $recorder->assertDispatched(StoreDeleted::class);
})->with('store-providers');

test('can create store with expiration', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);
    ProviderConfigured::store($provider);

    $created = Stores::create(
        name: 'Expiring Store',
        description: 'A store that expires after 7 days of inactivity.',
        expiresWhenIdleFor: days(7),
        provider: $provider,
    );

    expect($created->id)->not->toBeEmpty();

    Stores::delete($created->id, provider: $provider);
})->with('store-providers');

test('can add and remove file from store', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);
    ProviderConfigured::store($provider);

    $store = Stores::create('File Test Store', provider: $provider);

    $file = Files::put(
        Document::fromString('This is test content for the vector store.', 'text/plain')->as('test.txt'),
        provider: $provider,
    );

    $documentId = $store->add($file);

    expect($documentId)->not->toBeEmpty();

    $refreshed = $store->refresh();

    expect($refreshed->fileCounts->completed + $refreshed->fileCounts->pending)->toBeGreaterThanOrEqual(0);

    $removed = $store->remove($documentId, deleteFile: true);

    expect($removed)->toBeTrue();

    $store->delete();
})->with('store-providers');


    afterEach(function (): void {
        if (property_exists($this, 'fileSearchStore') && $this->fileSearchStore !== null) {
            $this->fileSearchStore->delete();
        }

        foreach ($this->fileSearchFileIds ?? [] as $fileId) {
            if ($fileId !== null) {
                Files::delete($fileId, provider: $this->provider);
            }
        }
    });

    test('can actually prompt an agent with file search data', function (string $provider, string $apiKey): void {
        ApiKey::required($apiKey);
        ProviderConfigured::store($provider);

        $this->provider = $provider;
        [$this->fileSearchStore, $this->fileSearchFileIds] = createFileSearchStore($provider);

        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$this->fileSearchStore->id]),
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap? Can you quote the section where it is mentioned?', provider: $provider);

        expect((string)$response)->toContain('Yes')->toContain('Valkey');
    })->with('file-search-providers');

    test('can actually prompt an agent with filtered search data', function (): void {
        ApiKey::required('OPENAI_API_KEY');
        ProviderConfigured::store('openai');

        $this->provider = 'openai';
        [$this->fileSearchStore, $this->fileSearchFileIds] = createFileSearchStore('openai');

        $instructions = 'Answer strictly based on the documents returned by the file search tool. '
            . 'Do not use prior knowledge. Respond with exactly one word: "Yes" or "No".';
        $prompt = 'Do any of the documents you have access to mention Valkey?';

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch([$this->fileSearchStore->id], where: ['company' => 'tailwind']),
            ],
        )->prompt($prompt, provider: 'openai');

        expect(trim((string)$response))->toStartWith('No');

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch(
                    [$this->fileSearchStore->id],
                    where: fn($query) => $query->where('company', 'cakephp'),
                ),
            ],
        )->prompt($prompt, provider: 'openai');

        expect(trim((string)$response))->toStartWith('Yes');
    });
