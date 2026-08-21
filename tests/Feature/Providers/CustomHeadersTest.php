<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

function fakeCustomHeadersEmbeddingsResponse(): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

test('configured headers are sent with provider requests', function (): void {
    Configure::write('Ai.providers.voyageai', [
        ...(array)Configure::read('Ai.providers.voyageai'),
        'key' => 'test-key',
        'headers' => ['X-Session-Affinity' => 'abc-123'],
    ]);

    aiHttpFake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('X-Session-Affinity', 'abc-123'));
});

test('configured headers override gateway default headers', function (): void {
    Configure::write('Ai.providers.voyageai', [
        ...(array)Configure::read('Ai.providers.voyageai'),
        'key' => 'test-key',
        'headers' => ['authorization' => 'Bearer proxy-token'],
    ]);

    aiHttpFake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer proxy-token'));
});
