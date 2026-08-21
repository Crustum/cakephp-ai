<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

/**
 * Maps embeddings inputs to Gemini content parts.
 */
trait MapsEmbeddingInputsTrait
{
    /**
     * Normalize model names accepted by the Gemini API.
     *
     * @param string $model Model name
     * @return string
     */
    protected function normalizeEmbeddingModel(string $model): string
    {
        return str_starts_with($model, 'models/') ? substr($model, 7) : $model;
    }

    /**
     * Map an embeddings input to a Gemini content part.
     *
     * @param mixed $input Input to embed
     * @return array<string, mixed>
     */
    protected function mapEmbeddingInput(mixed $input): array
    {
        return is_string($input) ? ['text' => $input] : $this->mapAttachment($input);
    }
}
