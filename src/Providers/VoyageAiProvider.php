<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\Video;
use Crustum\Ai\Gateway\VoyageAi\VoyageAiGateway;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasRerankingGatewayTrait;
use Crustum\Ai\Providers\Trait\ReranksTrait;
use InvalidArgumentException;
use Override;

/**
 * Voyage AI embeddings and reranking provider.
 */
class VoyageAiProvider extends Provider implements EmbeddingProvider, RerankingProvider
{
    use GeneratesEmbeddingsTrait;
    use HasEmbeddingGatewayTrait;
    use HasRerankingGatewayTrait;
    use ReranksTrait;

    /**
     * Provider configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Event manager instance.
     */
    protected EventManagerInterface $events;

    /**
     * Shared Voyage AI gateway instance.
     */
    protected ?VoyageAiGateway $voyageAiGateway = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Provider configuration
     * @param \Cake\Event\EventManagerInterface|null $events Event manager instance
     */
    public function __construct(
        array $config,
        ?EventManagerInterface $events = null,
    ) {
        $this->config = $config;
        $this->events = $events ?? EventManager::instance();
        $this->config['name'] ??= 'voyageai';
        $this->config['driver'] ??= 'voyageai';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    /**
     * Get the shared Voyage AI gateway instance.
     *
     * @return \Crustum\Ai\Gateway\VoyageAi\VoyageAiGateway
     */
    protected function voyageAiGateway(): VoyageAiGateway
    {
        return $this->voyageAiGateway ??= new VoyageAiGateway($this->events);
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->voyageAiGateway();
    }

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= $this->voyageAiGateway();
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'voyage-4';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     *
     * @return int
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'rerank-2.5-lite';
    }

    /**
     * Validate embeddings inputs against Voyage AI's supported media types.
     *
     * @param array<int, mixed> $inputs Inputs to embed
     * @param string $model Model name
     * @return void
     */
    protected function validateEmbeddingInputs(array $inputs, string $model): void
    {
        foreach ($inputs as $input) {
            if (is_string($input)) {
                continue;
            }

            if ($input instanceof Image && !$input instanceof ProviderImage) {
                if ($this->isVoyageMultimodalModel($model)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI image embeddings. Use [voyage-multimodal-3.5] or [voyage-multimodal-3].",
                );
            }

            if ($input instanceof Video) {
                if ($model === 'voyage-multimodal-3.5') {
                    continue;
                }

                throw new InvalidArgumentException(
                    "Model [{$model}] does not support Voyage AI video embeddings. Use [voyage-multimodal-3.5].",
                );
            }

            throw new InvalidArgumentException(
                'Provider [voyageai] only supports text, image, and video embeddings inputs.',
            );
        }
    }

    /**
     * Determine if the given model supports Voyage AI multimodal embeddings.
     *
     * @param string $model Model name
     * @return bool
     */
    protected function isVoyageMultimodalModel(string $model): bool
    {
        return in_array($model, ['voyage-multimodal-3.5', 'voyage-multimodal-3'], true);
    }
}
