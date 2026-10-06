<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi;

use Cake\Event\EventManagerInterface;
use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Gateway\OpenAi\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\CreatesOpenAiClientTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\HandlesTextGenerationTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\HandlesTextStepsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\MapsToolsTrait;
use Crustum\Ai\Gateway\OpenAi\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesAudioFilenamesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;
use LogicException;

/**
 * OpenAI API gateway.
 */
class OpenAiGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesOpenAiClientTrait;
    use HandlesTextGenerationTrait;
    use HandlesTextStepsTrait;
    use MapsAttachmentsTrait;
    use MapsMessagesTrait;
    use MapsToolsTrait;
    use ParsesTextResponsesTrait;
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
     * Generate an image from the given prompt.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param string|null $size Image size
     * @param string|null $quality Image quality
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\ImageResponse
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
        $hasAttachments = Value::filled($attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $hasAttachments
                ? $this->sendImageEditRequest($provider, $model, $prompt, $attachments, $size, $quality, $timeout, $providerOptions)
                : $this->sendImageGenerationRequest($provider, $model, $prompt, $size, $quality, $timeout, $providerOptions),
        );

        $data = $response->getJson() ?? [];

        return new ImageResponse(
            array_map(
                fn(array $image): GeneratedImage => new GeneratedImage(
                    $image['b64_json'] ?? '',
                    'image/png',
                ),
                $data['data'] ?? [],
            ),
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Send an image generation request.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param string|null $size Image size
     * @param string|null $quality Image quality
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function sendImageGenerationRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        ?string $size,
        ?string $quality,
        ?int $timeout,
        array $providerOptions = [],
    ): HttpResponseInterface {
        return $this->client($provider, $timeout ?? 120)->post('images/generations', [
            ...$providerOptions,
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...(str_starts_with($model, 'gpt-image')
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ]);
    }

    /**
     * Send an image edit request with attachments.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image|\Laminas\Diactoros\UploadedFile> $attachments Image attachments
     * @param string|null $size Image size
     * @param string|null $quality Image quality
     * @param int|null $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function sendImageEditRequest(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments,
        ?string $size,
        ?string $quality,
        ?int $timeout,
        array $providerOptions = [],
    ): HttpResponseInterface {
        $request = $this->client($provider, $timeout ?? 120);

        $isGptImage = str_starts_with($model, 'gpt-image');
        $field = $isGptImage ? 'image[]' : 'image';

        foreach ($attachments as $attachment) {
            $content = match (true) {
                $attachment instanceof Image && $attachment instanceof StorableFile => $attachment->content(),
                $attachment instanceof UploadedFile => $attachment->getStream()->getContents(),
                default => throw new InvalidArgumentException(
                    'Unsupported image attachment type [' . get_debug_type($attachment) . ']',
                ),
            };

            $request = $request->attach($field, $content, 'image.png');
        }

        return $request->post('images/edits', array_merge($providerOptions, array_filter([
            'model' => $model,
            'prompt' => $prompt,
            ...$provider->defaultImageOptions($size, $quality),
            ...($isGptImage
                ? ['moderation' => 'low']
                : ['response_format' => 'b64_json']),
        ])));
    }

    /**
     * Generate audio from the given text.
     *
     * @param \Crustum\Ai\Contracts\Providers\AudioProvider $provider Audio provider
     * @param string $model Model name
     * @param string $text Text to convert to audio
     * @param string $voice Voice identifier
     * @param string|null $instructions Optional instructions
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\AudioResponse
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
        $voice = match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('audio/speech', array_merge(['speed' => 1.0], $providerOptions, array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
                'response_format' => 'mp3',
                'instructions' => $instructions,
            ]))),
        );

        return new AudioResponse(
            base64_encode($response->getStringBody()),
            new Usage(),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }

    /**
     * Generate text from the given audio.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider Transcription provider
     * @param string $model Model name
     * @param \Crustum\Ai\Contracts\Files\TranscribableAudio $audio Audio input
     * @param string|null $language Language code
     * @param bool $diarize Whether to perform speaker diarization
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
        if ($diarize && Value::filled($providerOptions['prompt'] ?? null)) {
            throw new LogicException('OpenAI does not support the `prompt` option for diarized transcriptions.');
        }

        if ($provider->driver() === 'openai' && !$diarize) {
            $model = str_replace('-diarize', '', $model);
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $diarize ? 'diarized_json' : 'json',
                ]))),
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
                inputTokens: (int)Hash::get($data, 'usage.input_tokens', 0),
                outputTokens: (int)Hash::get($data, 'usage.output_tokens', 0),
                audioSeconds: Hash::get($data, 'usage.seconds') ?? Hash::get($data, 'duration'),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings for the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, string> $inputs Input texts
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

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $data['data'] ?? [];

        return new EmbeddingsResponse(
            collection($rows)->extract('embedding')->toList(),
            new Usage($data['usage']['prompt_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }
}
