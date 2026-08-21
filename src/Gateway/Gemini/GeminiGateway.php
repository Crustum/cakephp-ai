<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\Gemini\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Gemini\Trait\CreatesGeminiClientTrait;
use Crustum\Ai\Gateway\Gemini\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\Gemini\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\Gemini\Trait\MapsEmbeddingInputsTrait;
use Crustum\Ai\Gateway\Gemini\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\Gemini\Trait\MapsToolsTrait;
use Crustum\Ai\Gateway\Gemini\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\WrapsPcmAudioTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Value;
use Generator;
use RuntimeException;

/**
 * Gemini generateContent API gateway.
 */
class GeminiGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesGeminiClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsEmbeddingInputsTrait;
    use MapsMessagesTrait;
    use MapsToolsTrait;
    use ParsesTextResponsesTrait;
    use HandlesFailoverErrorsTrait;
    use ParsesServerSentEventsTrait;
    use WrapsPcmAudioTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate text for a single Gemini generateContent step.
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
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post("models/{$model}:generateContent", $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Gemini generateContent step.
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

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post("models/{$model}:streamGenerateContent?alt=sse", $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
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
    ): ImageResponse {
        $parts = [['text' => $prompt]];

        if (Value::filled($attachments)) {
            $parts = array_merge($parts, $this->mapAttachments(collection($attachments)));
        }

        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => array_filter([
                'responseModalities' => ['IMAGE', 'TEXT'],
                'imageConfig' => array_filter([
                    'imageSize' => $imageOptions['image_size'] ?? null,
                    'aspectRatio' => $imageOptions['aspect_ratio'] ?? null,
                ]),
            ]),
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 120)->post("models/{$model}:generateContent", $body),
        );

        $data = $response->getJson() ?? [];

        $images = (new Collection($data['candidates'][0]['content']['parts'] ?? []))
            ->filter(fn(mixed $part): bool => isset($part['inlineData']))
            ->values()
            ->map(fn(mixed $part): GeneratedImage => new GeneratedImage(
                $part['inlineData']['data'],
                $part['inlineData']['mimeType'],
            ));

        return new ImageResponse(
            $images,
            $this->extractUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param \Crustum\Ai\Contracts\Providers\EmbeddingProvider $provider Embedding provider
     * @param string $model Model name
     * @param array<int, mixed> $inputs Inputs to embed
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
        $model = $this->normalizeEmbeddingModel($model);

        $requests = array_map(fn(mixed $input): array => array_merge(array_diff_key($providerOptions, ['output_dimensionality' => true]), [
            'model' => "models/{$model}",
            'content' => ['parts' => [$this->mapEmbeddingInput($input)]],
            'outputDimensionality' => $dimensions,
        ]), $inputs);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post("models/{$model}:batchEmbedContents", [
                'requests' => $requests,
            ]),
        );

        $data = $response->getJson() ?? [];

        return new EmbeddingsResponse(
            (new Collection($data['embeddings'] ?? []))->extract('values')->toList(),
            $data['usageMetadata']['promptTokenCount'] ?? 0,
            new Meta($provider->name(), $model),
        );
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
     * @return \Crustum\Ai\Responses\AudioResponse
     * @throws \RuntimeException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post("models/{$model}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $instructions !== null && trim($instructions) !== ''
                            ? trim($instructions) . "\n\n" . $text
                            : $text,
                    ]],
                ]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => match ($voice) {
                                    'default-female' => 'Kore',
                                    'default-male' => 'Puck',
                                    default => $voice,
                                },
                            ],
                        ],
                    ],
                ],
            ]),
        );

        $data = $response->getJson() ?? [];

        $encodedAudio = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;

        if (!is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from Gemini API.');
        }

        $pcm = base64_decode($encodedAudio, true);

        if ($pcm === false) {
            throw new RuntimeException('Gemini returned invalid audio data.');
        }

        return new AudioResponse(
            base64_encode($this->pcmToWav($pcm)),
            new Meta($provider->name(), $model),
            'audio/wav',
        );
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
        $inlineData = ['inlineData' => [
            'mimeType' => $audio->mimeType() ?? 'audio/mp3',
            'data' => base64_encode($audio->content()),
        ]];

        if ($diarize) {
            $prompt = $language !== null
                ? "Transcribe this audio with timestamps in {$language}. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time."
                : 'Transcribe this audio with timestamps. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn(): HttpResponseInterface => $this->client($provider, $timeout)->post("models/{$model}:generateContent", array_merge($providerOptions, [
                    'contents' => [[
                        'parts' => [['text' => $prompt], $inlineData],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'transcript' => ['type' => 'STRING'],
                                'segments' => [
                                    'type' => 'ARRAY',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'text' => ['type' => 'STRING'],
                                            'start_time' => ['type' => 'STRING'],
                                            'end_time' => ['type' => 'STRING'],
                                        ],
                                        'required' => ['text', 'start_time', 'end_time'],
                                    ],
                                ],
                            ],
                            'required' => ['transcript', 'segments'],
                        ],
                    ],
                ])),
            );

            $data = json_decode($response->getJson()['candidates'][0]['content']['parts'][0]['text'] ?? '{}', true);

            $text = $data['transcript'] ?? '';

            $segments = (new Collection($data['segments'] ?? []))->map(fn(mixed $seg): TranscriptionSegment => new TranscriptionSegment(
                $seg['text'],
                '',
                $this->timestampToSeconds($seg['start_time'] ?? '0:00'),
                $this->timestampToSeconds($seg['end_time'] ?? '0:00'),
            ));
        } else {
            $prompt = $language !== null
                ? "Transcribe this audio. Output only the transcription in {$language}."
                : 'Transcribe this audio. Output only the transcription text.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn(): HttpResponseInterface => $this->client($provider, $timeout)->post("models/{$model}:generateContent", array_merge($providerOptions, [
                    'contents' => [[
                        'parts' => [['text' => $prompt], $inlineData],
                    ]],
                ])),
            );

            $text = $response->getJson()['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $segments = new Collection([]);
        }

        $usageMeta = $response->getJson()['usageMetadata'] ?? [];

        return new TranscriptionResponse(
            trim((string)$text),
            $segments,
            new Usage(
                promptTokens: $usageMeta['promptTokenCount'] ?? 0,
                completionTokens: $usageMeta['candidatesTokenCount'] ?? 0,
                reasoningTokens: $usageMeta['thoughtsTokenCount'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Convert a timestamp string to seconds.
     *
     * @param string $timestamp Timestamp string
     * @return float
     */
    protected function timestampToSeconds(string $timestamp): float
    {
        $timestamp = str_replace(',', '.', trim($timestamp));

        if (preg_match('/^\d+(?:\.\d+)?$/', $timestamp) === 1) {
            return (float)$timestamp;
        }

        if (preg_match('/^\d+(?::\d+){1,2}(?:\.\d+)?$/', $timestamp) !== 1) {
            return 0.0;
        }

        $parts = array_reverse(explode(':', $timestamp));

        return (float)$parts[0]
            + (float)($parts[1] ?? 0) * 60
            + (float)($parts[2] ?? 0) * 3600;
    }
}
