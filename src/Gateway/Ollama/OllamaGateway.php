<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\Ollama\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Ollama\Trait\CreatesOllamaClientTrait;
use Crustum\Ai\Gateway\Ollama\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\Ollama\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\Ollama\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\Ollama\Trait\MapsToolsTrait;
use Crustum\Ai\Gateway\Ollama\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Utility\Value;
use Generator;

/**
 * Ollama Chat and Embeddings API gateway.
 */
class OllamaGateway implements EmbeddingGateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesOllamaClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsMessagesTrait;
    use MapsToolsTrait;
    use ParsesTextResponsesTrait;
    use HandlesFailoverErrorsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate text for a single Ollama Chat API step.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('api/chat', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Ollama Chat API step.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return \Generator<int, \Crustum\Ai\Streaming\Event\StreamEvent, mixed, \Crustum\Ai\Gateway\StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);
        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('api/chat', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string|\Crustum\Ai\Files\Audio|\Crustum\Ai\Files\Document|\Crustum\Ai\Files\Image|\Crustum\Ai\Files\Video> $inputs Inputs to embed
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
        $body = array_merge($providerOptions, array_filter([
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('api/embed', $body),
        );

        $data = $response->getJson() ?? [];

        if (!$data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Ollama Error: %s',
                $data['error'] ?? 'Unknown Ollama error.',
            ));
        }

        return new EmbeddingsResponse(
            $data['embeddings'] ?? [],
            $data['prompt_eval_count'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
