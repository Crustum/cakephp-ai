<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\EmbeddingsGenerated;
use Crustum\Ai\Event\GeneratingEmbeddings;
use Crustum\Ai\Prompts\EmbeddingsPrompt;
use Crustum\Ai\Responses\EmbeddingsResponse;
use InvalidArgumentException;

/**
 * Generates embeddings through the provider's embedding gateway.
 */
trait GeneratesEmbeddingsTrait
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to embed
     * @param int|null $dimensions Embedding dimensions
     * @param string|null $model Model name
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    public function embeddings(
        array $inputs,
        ?int $dimensions = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        if ($model !== null && $dimensions === null) {
            if (!$this->supportsNativeEmbeddingDimensions()) {
                throw new InvalidArgumentException('Dimensions must be provided when model is specified.');
            }

            $dimensions = 0;
        }

        $invocationId = Text::uuid();

        $model ??= $this->defaultEmbeddingsModel();
        $dimensions ??= $this->defaultEmbeddingsDimensions();

        $prompt = new EmbeddingsPrompt($inputs, $dimensions, $this, $model, $timeout, $providerOptions);

        if (Ai::manager()->embeddingsAreFaked()) {
            Ai::manager()->recordEmbeddingsGeneration($prompt);
        } else {
            $this->validateEmbeddingInputs($inputs, $model);
        }

        $this->events->dispatch(new GeneratingEmbeddings(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->embeddingGateway()->generateEmbeddings(
            $this,
            $model,
            $inputs,
            $dimensions,
            $timeout,
            $providerOptions,
        );

        $this->events->dispatch(new EmbeddingsGenerated(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }

    /**
     * Determine if the provider may omit dimensions and use a model's native embedding dimensions.
     *
     * @return bool
     */
    protected function supportsNativeEmbeddingDimensions(): bool
    {
        return false;
    }

    /**
     * Validate embeddings inputs against the provider's supported media types.
     *
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to validate
     * @param string $model Model name
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            throw new InvalidArgumentException(
                'Provider [' . $this->driver() . '] only supports text embeddings inputs.',
            );
        }
    }
}
