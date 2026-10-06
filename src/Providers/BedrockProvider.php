<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\RerankingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\RerankingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\Bedrock\BedrockImageGateway;
use Crustum\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Crustum\Ai\Gateway\Bedrock\BedrockTextGateway;
use Crustum\Ai\Providers\Trait\GeneratesEmbeddingsTrait;
use Crustum\Ai\Providers\Trait\GeneratesImagesTrait;
use Crustum\Ai\Providers\Trait\GeneratesTextTrait;
use Crustum\Ai\Providers\Trait\HasEmbeddingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasImageGatewayTrait;
use Crustum\Ai\Providers\Trait\HasRerankingGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTextGatewayTrait;
use Crustum\Ai\Providers\Trait\ReranksTrait;
use Crustum\Ai\Providers\Trait\StreamsTextTrait;
use Override;

/**
 * AWS Bedrock text, image, and embeddings provider.
 */
class BedrockProvider extends Provider implements EmbeddingProvider, ImageProvider, RerankingProvider, TextProvider
{
    use GeneratesEmbeddingsTrait;
    use GeneratesImagesTrait;
    use GeneratesTextTrait;
    use HasEmbeddingGatewayTrait;
    use HasImageGatewayTrait;
    use HasRerankingGatewayTrait;
    use HasTextGatewayTrait;
    use ReranksTrait;
    use StreamsTextTrait;

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
     * Shared Bedrock text gateway instance.
     */
    protected ?BedrockTextGateway $bedrockTextGateway = null;

    /**
     * Shared Bedrock image gateway instance.
     */
    protected ?BedrockImageGateway $bedrockImageGateway = null;

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
        $this->config['name'] ??= 'bedrock';
        $this->config['driver'] ??= 'bedrock';
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
        return array_filter([
            'access_key_id' => $this->config['access_key_id'] ?? null,
            'secret_access_key' => $this->config['secret_access_key'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
            'key' => $this->config['key'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function additionalConfiguration(): array
    {
        return [
            'region' => $this->config['region'] ?? 'us-east-1',
            'use_default_credential_provider' => $this->config['use_default_credential_provider'] ?? true,
            'headers' => $this->config['headers'] ?? [],
            'assume_role' => [
                'arn' => $this->config['assume_role']['arn'] ?? null,
                'session_name' => $this->config['assume_role']['session_name'] ?? null,
                'duration_seconds' => $this->config['assume_role']['duration_seconds'] ?? null,
                'external_id' => $this->config['assume_role']['external_id'] ?? null,
            ],
        ];
    }

    /**
     * Get the shared Bedrock text gateway instance.
     *
     * @return \Crustum\Ai\Gateway\Bedrock\BedrockTextGateway
     */
    protected function bedrockTextGateway(): BedrockTextGateway
    {
        return $this->bedrockTextGateway ??= new BedrockTextGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\StepTextGateway
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->bedrockTextGateway();
    }

    /**
     * Get the provider's embedding gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\EmbeddingGateway
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->bedrockTextGateway();
    }

    /**
     * Get the provider's image gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\ImageGateway
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->bedrockImageGateway ??= new BedrockImageGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     *
     * @return string
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'global.anthropic.claude-sonnet-5-5';
    }

    /**
     * Get the name of the cheapest text model.
     *
     * @return string
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'global.anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     *
     * @return string
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'global.anthropic.claude-opus-5-5';
    }

    /**
     * Get the name of the default embeddings model.
     *
     * @return string
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'amazon.titan-embed-text-v2:0';
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
     * Get the name of the default image model.
     *
     * @return string
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'amazon.nova-canvas-v1:0';
    }

    /**
     * Get the name of the default reranking model.
     *
     * @return string
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'cohere.rerank-v3-5:0';
    }

    /**
     * Get the provider's reranking gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\RerankingGateway
     */
    public function rerankingGateway(): RerankingGateway
    {
        if (!isset($this->rerankingGateway)) {
            $this->rerankingGateway = new BedrockRerankingGateway();
        }

        return $this->rerankingGateway;
    }

    /**
     * Get the default / normalized image options for the provider.
     *
     * @param string|null $size Image size
     * @param string|null $quality Image quality
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return [
            'quality' => match ($quality) {
                'high', 'premium' => 'premium',
                'low', 'medium', 'standard', null => 'standard',
                default => $quality,
            },
            'size' => match ($size) {
                '2:3' => '768x1152',
                '3:2' => '1152x768',
                '1:1', null => '1024x1024',
                default => $size,
            },
        ];
    }
}
