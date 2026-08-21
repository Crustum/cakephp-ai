<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234';
});

test('ollama text requests use the configured base url', function (): void {
    configureOllamaProvider($this->customUrl);

    aiHttpFake([
        '*' => aiHttpResponse([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello from local model'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->text)->toBe('Hello from local model');

    aiAssertHttpSentCount(1);
    ollamaAssertRequestSent('POST', $this->customUrl . '/api/chat');
});

test('ollama requests fall back to the default base url', function (): void {
    configureOllamaProvider();

    aiHttpFake([
        '*' => aiHttpResponse([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello from Ollama'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->text)->toBe('Hello from Ollama');

    aiAssertHttpSentCount(1);
    ollamaAssertRequestSent('POST', 'http://localhost:11434/api/chat');
});

test('ollama embeddings use the configured base url', function (): void {
    configureOllamaProvider($this->customUrl);

    aiHttpFake([
        '*' => aiHttpResponse([
            'model' => 'nomic-embed-text',
            'embeddings' => [[0.1, 0.2, 0.3]],
            'prompt_eval_count' => 5,
        ]),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    ollamaAssertRequestSent('POST', $this->customUrl . '/api/embed');
});

function configureOllamaProvider(?string $url = null): void
{
    Configure::write('Ai.providers.ollama.key', '');

    if ($url !== null) {
        Configure::write('Ai.providers.ollama.url', $url);
    } else {
        Configure::delete('Ai.providers.ollama.url');
    }
}

function ollamaAssertRequestSent(string $method, string $url): void
{
    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->method() === $method
        && $request->url() === $url);
}
