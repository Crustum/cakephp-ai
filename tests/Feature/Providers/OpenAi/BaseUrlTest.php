<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Stores;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234/v1';
});

test('openai text requests use the configured base url', function (): void {
    configureOpenAiProvider($this->customUrl);

    aiHttpFake([
        '*' => aiHttpResponse([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Hello from local model',
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->text)->toBe('Hello from local model');

    aiAssertHttpSentCount(1);
    openAiAssertRequestSent('POST', $this->customUrl . '/responses');
});

test('openai file requests use the configured base url', function (): void {
    configureOpenAiProvider($this->customUrl);

    aiHttpFake(fn(AiHttpRequest $request): AiHttpResponseDefinition => match ([$request->method(), $request->url()]) {
        ['POST', $this->customUrl . '/files'] => aiHttpResponse(['id' => 'file_123']),
        ['GET', $this->customUrl . '/files/file_123'] => aiHttpResponse(['id' => 'file_123']),
        ['DELETE', $this->customUrl . '/files/file_123'] => aiHttpResponse(),
        default => aiHttpResponse(['unexpected_url' => $request->url()], 500),
    });

    $stored = Files::put(
        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt'),
        provider: 'openai',
    );

    $retrieved = Files::get($stored->id, provider: 'openai');

    Files::delete($stored->id, provider: 'openai');

    expect($stored->id)->toBe('file_123')
        ->and($retrieved->id)->toBe('file_123');

    aiAssertHttpSentCount(3);
    openAiAssertRequestSent('POST', $this->customUrl . '/files');
    openAiAssertRequestSent('GET', $this->customUrl . '/files/file_123');
    openAiAssertRequestSent('DELETE', $this->customUrl . '/files/file_123');
});

test('openai store requests use the configured base url', function (): void {
    configureOpenAiProvider($this->customUrl);

    aiHttpFake(fn(AiHttpRequest $request): AiHttpResponseDefinition => match ([$request->method(), $request->url()]) {
        ['POST', $this->customUrl . '/vector_stores'] => aiHttpResponse(['id' => 'vs_123']),
        ['GET', $this->customUrl . '/vector_stores/vs_123'] => aiHttpResponse([
            'id' => 'vs_123',
            'name' => 'Local Store',
            'status' => 'completed',
            'file_counts' => [
                'completed' => 0,
                'in_progress' => 0,
                'failed' => 0,
            ],
        ]),
        ['POST', $this->customUrl . '/vector_stores/vs_123/files'] => aiHttpResponse(['id' => 'vsfile_123']),
        ['DELETE', $this->customUrl . '/vector_stores/vs_123/files/vsfile_123'] => aiHttpResponse(['deleted' => true]),
        ['DELETE', $this->customUrl . '/vector_stores/vs_123'] => aiHttpResponse(['deleted' => true]),
        default => aiHttpResponse(['unexpected_url' => $request->url()], 500),
    });

    $store = Stores::create('Local Store', provider: 'openai');
    $document = $store->add('file_123');
    $removed = $store->remove($document->id());
    $deleted = $store->delete();

    expect($store->id)->toBe('vs_123')
        ->and($store->name)->toBe('Local Store')
        ->and($document->id())->toBe('vsfile_123')
        ->and($removed)->toBeTrue()
        ->and($deleted)->toBeTrue();

    aiAssertHttpSentCount(5);
    openAiAssertRequestSent('POST', $this->customUrl . '/vector_stores');
    openAiAssertRequestSent('GET', $this->customUrl . '/vector_stores/vs_123');
    openAiAssertRequestSent('POST', $this->customUrl . '/vector_stores/vs_123/files');
    openAiAssertRequestSent('DELETE', $this->customUrl . '/vector_stores/vs_123/files/vsfile_123');
    openAiAssertRequestSent('DELETE', $this->customUrl . '/vector_stores/vs_123');
});

test('openai requests fall back to the default base url', function (): void {
    configureOpenAiProvider();

    aiHttpFake([
        '*' => aiHttpResponse([
            'id' => 'resp_456',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Hello from OpenAI',
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->text)->toBe('Hello from OpenAI');

    aiAssertHttpSentCount(1);
    openAiAssertRequestSent('POST', 'https://api.openai.com/v1/responses');
});

function configureOpenAiProvider(?string $url = null): void
{
    Configure::write('Ai.providers.openai.key', 'test-key');
    if ($url !== null) {
        Configure::write('Ai.providers.openai.url', $url);
    }
}

function openAiAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === $method
        && $request->url() === $url);
}
