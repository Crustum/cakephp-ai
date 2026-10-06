<?php
declare(strict_types=1);

use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;

test('embeddings response serializes its usage under a usage key', function (): void {
    $response = new EmbeddingsResponse([[0.1, 0.2]], new Usage(10), new Meta('openai', 'text-embedding-3-small'));

    $serialized = json_decode((string)json_encode($response), true);

    expect($serialized)->not->toHaveKey('tokens')
        ->and($serialized['usage']['input_tokens'])->toBe(10);
});
