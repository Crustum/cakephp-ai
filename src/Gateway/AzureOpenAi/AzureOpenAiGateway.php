<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\AzureOpenAi;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Gateway\AzureOpenAi\Trait\CreatesAzureOpenAiClientTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\HandlesTextGenerationTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\HandlesTextStepsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsToolsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Support\ObjectSchema;
use Crustum\Ai\Tools\ToolNameResolver;
use Crustum\Ai\Utility\Value;
use Crustum\JsonSchema\JsonSchemaTypeFactory;
use LogicException;

/**
 * Azure OpenAI v1-compatible API gateway.
 */
class AzureOpenAiGateway implements EmbeddingGateway, ImageGateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesAzureOpenAiClientTrait;
    use HandlesFailoverErrorsTrait;
    use HandlesTextGenerationTrait;
    use HandlesTextStepsTrait;
    use MapsAttachmentsTrait;
    use MapsMessagesTrait;
    use MapsToolsTrait;
    use ParsesServerSentEventsTrait;
    use ParsesTextResponsesTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string> $inputs Inputs to embed
     * @param int $dimensions Embedding dimensions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'dimensions' => $dimensions,
            ])),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->extract('embedding')->toList(),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate an image.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param '3:2'|'2:3'|'1:1'|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ImageResponse
     * @throws \LogicException
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        if (Value::filled($attachments)) {
            throw new LogicException('Azure OpenAI does not support image editing.');
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 120)->post('images/generations', [
                ...$providerOptions,
                'model' => $model,
                'prompt' => $prompt,
                'moderation' => 'low',
                ...$provider->defaultImageOptions($size, $quality),
            ]),
        );

        $data = $response->getJson() ?? [];

        return new ImageResponse(
            (new Collection($data['data'] ?? []))->map(fn(array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map a regular tool to an Azure OpenAI function definition.
     *
     * @param \Crustum\Ai\Contracts\Tool $tool Tool instance
     * @param bool $defer Whether the tool should be deferred for hosted tool search
     * @return array<string, mixed>
     */
    protected function mapTool(Tool $tool, bool $defer = false): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory());

        $schemaArray = Value::filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        $definition = array_filter([
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string)$tool->description(),
            'parameters' => Value::filled($schemaArray) ? [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object)[],
                'required' => $schemaArray['required'] ?? [],
            ] : null,
        ]);

        if ($defer) {
            $definition['defer_loading'] = true;
        }

        return $definition;
    }
}
