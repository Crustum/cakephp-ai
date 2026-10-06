<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\EmbeddingGateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Exception\AiException;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\CreatesOpenAiCompatibleClientTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\PerformsChatCompletionStepsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesAudioFilenamesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use InvalidArgumentException;

/**
 * OpenAI-compatible Chat Completions API gateway.
 */
class OpenAiCompatibleGateway implements EmbeddingGateway, StepTextGateway, TranscriptionGateway
{
    use BuildsTextRequestsTrait;
    use CreatesOpenAiCompatibleClientTrait;
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
        if (($providerOptions['encoding_format'] ?? 'float') !== 'float') {
            throw new InvalidArgumentException('This openai-compatible provider only supports float embedding responses.');
        }

        $body = array_merge($providerOptions, array_filter([
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            $this->parseEmbeddings($data, count($inputs)),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Parse the embedding vectors on an OpenAI-compatible HttpResponseInterface.
     *
     * @param array<string, mixed> $data HttpResponseInterface data
     * @param int $expectedCount Expected number of embeddings
     * @return array<int, array<int, float>>
     */
    protected function parseEmbeddings(array $data, int $expectedCount): array
    {
        $embeddings = (new Collection($data['data'] ?? []))->extract('embedding');

        if ($embeddings->count() !== $expectedCount) {
            throw new AiException('OpenAI-compatible Error: [invalid_response] The HttpResponseInterface did not contain the expected number of embeddings.');
        }

        return $embeddings->map(function (mixed $embedding): array {
            if (!is_array($embedding) || $embedding === [] || array_filter($embedding, is_numeric(...)) !== $embedding) {
                throw new AiException('OpenAI-compatible Error: [invalid_response] The HttpResponseInterface contained an invalid embedding vector.');
            }

            return array_map(floatval(...), $embedding);
        })->toList();
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge(array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]), $providerOptions)),
        );

        $data = $response->getJson() ?? [];

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collection(array_map(
                fn(array $segment): TranscriptionSegment => new TranscriptionSegment(
                    $segment['text'] ?? '',
                    $segment['speaker'] ?? '',
                    $segment['start'] ?? 0,
                    $segment['end'] ?? 0,
                ),
                $data['segments'] ?? [],
            )),
            new TranscriptionUsage(
                inputTokens: (int)(Hash::get($data, 'usage.input_tokens') ?? Hash::get($data, 'usage.prompt_tokens', 0)),
                outputTokens: (int)(Hash::get($data, 'usage.output_tokens') ?? Hash::get($data, 'usage.completion_tokens', 0)),
                audioSeconds: Hash::get($data, 'usage.seconds') ?? Hash::get($data, 'duration'),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>|null
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return $provider->additionalConfiguration()['stream_options'] ?? null;
    }
}
