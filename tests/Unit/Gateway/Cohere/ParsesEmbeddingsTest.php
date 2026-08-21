<?php
declare(strict_types=1);

use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\Cohere\Trait\ParsesEmbeddingsTrait;

function cohereEmbeddingsParser(): object
{
    return new class
    {
        use ParsesEmbeddingsTrait;

        public function parse(mixed $embeddings): array
        {
            return $this->parseCohereEmbeddings($embeddings);
        }
    };
}

test('parses embeddings returned as a bare list of vectors', function (): void {
    $embeddings = cohereEmbeddingsParser()->parse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('unwraps the float type when embeddings are keyed by embedding type', function (): void {
    $embeddings = cohereEmbeddingsParser()->parse(['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]);

    expect($embeddings)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
});

test('throws when the response carries no float embeddings', function (): void {
    cohereEmbeddingsParser()->parse(['int8' => [[1, 2, 3]], 'binary' => [[1, 0, 1]]]);
})->throws(AiException::class, 'Cohere returned [int8, binary] embeddings, but only float embeddings are supported.');

test('returns an empty list when the embeddings are absent', function (): void {
    expect(cohereEmbeddingsParser()->parse([]))->toBe([]);
});

test('returns an empty list when the response body could not be decoded', function (): void {
    expect(cohereEmbeddingsParser()->parse(null))->toBe([]);
});
