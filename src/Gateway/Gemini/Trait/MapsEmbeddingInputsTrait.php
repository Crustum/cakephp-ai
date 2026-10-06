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
        if (is_string($input)) {
            return ['text' => $input];
        }

        $block = $this->mapAttachment($input);

        // The embeddings endpoint predates the Interactions API and still takes content parts.
        return isset($block['uri'])
            ? ['fileData' => array_filter([
                'mimeType' => $block['mime_type'] ?? null,
                'fileUri' => $block['uri'],
            ])]
            : ['inlineData' => array_filter([
                'mimeType' => $block['mime_type'] ?? null,
                'data' => $block['data'] ?? null,
            ])];
    }
}
