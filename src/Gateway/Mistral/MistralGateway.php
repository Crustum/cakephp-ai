<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Mistral;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\Mistral\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Mistral\Trait\CreatesMistralClientTrait;
use Crustum\Ai\Gateway\Mistral\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\Mistral\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\Mistral\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\PerformsChatCompletionStepsTrait;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesAudioFilenamesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\TranscriptionResponse;

/**
 * Mistral Chat, Embeddings, and Transcription API gateway.
 */
class MistralGateway implements EmbeddingGateway, StepTextGateway, TranscriptionGateway
{
    use BuildsTextRequestsTrait;
    use CreatesMistralClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsChatCompletionMessagesTrait;
    use MapsChatCompletionToolsTrait;
    use ParsesTextResponsesTrait;
    use PerformsChatCompletionStepsTrait;
    use HandlesFailoverErrorsTrait;
    use ParsesServerSentEventsTrait;
    use ResolvesAudioFilenamesTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Build the request body for the current text generation step.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider Text provider
     * @param string $model Model name
     * @param string|null $instructions System instructions
     * @param array<int, mixed> $messages Conversation messages
     * @param array<int, mixed> $tools Available tools
     * @param array<string, mixed>|null $schema Structured output schema
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Generation options
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Step context
     * @return array<string, mixed>
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
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
            ])),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            collection($data['data'] ?? [])->extract('embedding')->toList(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider Transcription provider
     * @param string $model Model name
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio to transcribe
     * @param string|null $language Optional language code
     * @param bool $diarize Whether to identify different speakers
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
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
        $params = ['model' => $model];

        if ($diarize) {
            $params['diarize'] = true;
            $params['timestamp_granularities'] = ['segment'];
        } elseif ($language) {
            $params['language'] = $language;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, $params)),
        );

        $data = $response->getJson() ?? [];

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collection($data['segments'] ?? [])->map(fn(array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'] ?? '',
                $segment['speaker_id'] ?? '',
                $segment['start'] ?? 0,
                $segment['end'] ?? 0,
            )),
            new Usage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        );
    }
}
