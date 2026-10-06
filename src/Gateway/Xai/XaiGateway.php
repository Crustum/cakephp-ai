<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Xai\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Xai\Trait\CreatesXaiClientTrait;
use Crustum\Ai\Gateway\Xai\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\Xai\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\Xai\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\Xai\Trait\MapsToolsTrait;
use Crustum\Ai\Gateway\Xai\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Value;
use Generator;
use LogicException;

/**
 * xAI Responses API gateway.
 */
class XaiGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesXaiClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsMessagesTrait;
    use MapsToolsTrait;
    use ParsesTextResponsesTrait;
    use HandlesFailoverErrorsTrait;
    use ParsesServerSentEventsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate text for a single xAI Responses API step.
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
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single xAI Responses API step.
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
                ->post('responses', $body),
        );

        return yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $response->getBody(),
        );
    }

    /**
     * Generate an image.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param string|null $size Image size
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
        throw new LogicException('Use XaiImageGateway for image generation.');
    }

    /**
     * Generate audio from the given text.
     *
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider Audio provider
     * @param string $model Model name
     * @param string $text Text to convert to audio
     * @param string $voice Voice to use
     * @param string|null $instructions Optional instructions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\AudioResponse
     * @throws \LogicException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        throw new LogicException('xAI does not support audio generation.');
    }

    /**
     * Generate text from the given audio.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider Transcription provider
     * @param string $model Model name
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio to transcribe
     * @param string|null $language Optional language code
     * @param bool $diarize Whether to identify speakers
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     * @throws \LogicException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new LogicException('xAI does not support transcription generation.');
    }

    /**
     * Generate embeddings for the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string> $inputs Inputs to embed
     * @param int $dimensions Embedding dimensions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\EmbeddingsResponse
     * @throws \LogicException
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        throw new LogicException('xAI does not support embedding generation.');
    }
}
