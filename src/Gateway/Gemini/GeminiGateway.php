<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
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
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Value;
use Generator;
use RuntimeException;

/**
 * Gemini Interactions API gateway.
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
     * Generate text for a single Gemini interaction step.
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
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, Value::filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Gemini interaction step.
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
                ->post('interactions?alt=sse', array_merge($body, ['stream' => true])),
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
        $content = [['type' => 'text', 'text' => $prompt]];

        if (Value::filled($attachments)) {
            $content = array_merge($content, $this->mapAttachments(collection($attachments)));
        }

        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $body = array_merge(['store' => false], $providerOptions, [
            'model' => $model,
            'input' => $content,
            'response_format' => array_replace_recursive($providerOptions['response_format'] ?? [], array_filter([
                'type' => 'image',
                'image_size' => $imageOptions['image_size'] ?? null,
                'aspect_ratio' => $imageOptions['aspect_ratio'] ?? null,
            ])),
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 120)->post('interactions', $body),
        );

        $data = $response->getJson() ?? [];

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\GeneratedImage> $images */
        $images = (new Collection($this->outputBlocks($data, 'image')))
            ->map(fn(mixed $block): GeneratedImage => new GeneratedImage(
                $block['data'],
                $block['mime_type'] ?? 'image/png',
            ));

        return new ImageResponse(
            $images,
            $this->extractImageUsage($data),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get the content blocks of the given type from an interaction response.
     *
     * @param array<string, mixed> $data Response data
     * @param string $type Block type
     * @return array<int, array<string, mixed>>
     */
    protected function outputBlocks(array $data, string $type): array
    {
        return (new Collection($data['steps'] ?? []))
            ->unfold(fn(array $step): array => $step['content'] ?? [])
            ->filter(fn(mixed $block): bool => is_array($block) && ($block['type'] ?? '') === $type && isset($block['data']))
            ->values()
            ->toList();
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
            new Usage($data['usageMetadata']['promptTokenCount'] ?? 0),
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
     * @param array<string, mixed> $providerOptions Provider-specific options
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
        array $providerOptions = [],
    ): AudioResponse {
        $body = array_merge(['store' => false], $providerOptions, [
            'model' => $model,
            'input' => $instructions !== null && trim($instructions) !== ''
                ? trim($instructions) . "\n\n" . $text
                : $text,
            'response_format' => ['type' => 'audio'],
            'generation_config' => array_replace_recursive($providerOptions['generation_config'] ?? [], [
                'speech_config' => [[
                    'voice' => match ($voice) {
                        'default-female' => 'Kore',
                        'default-male' => 'Puck',
                        default => $voice,
                    },
                ]],
            ]),
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->getJson() ?? [];

        $audio = $this->outputBlocks($data, 'audio')[0] ?? [];

        $encodedAudio = $audio['data'] ?? null;

        if (!is_string($encodedAudio) || $encodedAudio === '') {
            throw new RuntimeException('No audio data received from Gemini API.');
        }

        $pcm = base64_decode($encodedAudio, true);

        if ($pcm === false) {
            throw new RuntimeException('Gemini returned invalid audio data.');
        }

        return new AudioResponse(
            base64_encode($this->pcmToWav($pcm, $audio['sample_rate'] ?? 24000, $audio['channels'] ?? 1)),
            $this->extractUsage($data),
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
        $audioBlock = [
            'type' => 'audio',
            'mime_type' => $audio->mimeType() ?? 'audio/mp3',
            'data' => base64_encode($audio->content()),
        ];

        // Only the transcribe models accept a transcription config; the rest are asked in prose.
        return str_contains($model, 'transcribe')
            ? $this->transcribeWithConfig($provider, $model, $audioBlock, $language, $diarize, $timeout, $providerOptions)
            : $this->transcribeWithPrompt($provider, $model, $audioBlock, $language, $diarize, $timeout, $providerOptions);
    }

    /**
     * Transcribe the given audio using the Gemini transcription config.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider Transcription provider
     * @param string $model Model name
     * @param array<string, mixed> $audioBlock Audio content block
     * @param string|null $language Optional language code
     * @param bool $diarize Whether to identify speakers
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    protected function transcribeWithConfig(
        TranscriptionProvider $provider,
        string $model,
        array $audioBlock,
        ?string $language,
        bool $diarize,
        int $timeout,
        array $providerOptions,
    ): TranscriptionResponse {
        $transcriptionConfig = array_filter([
            'language_codes' => $language !== null ? [$language] : null,
            'mode' => $diarize ? [
                'type' => 'verbatim',
                'diarization_mode' => 'speaker',
                'timestamp_granularities' => ['word'],
            ] : null,
        ], static fn(mixed $value): bool => $value !== null);

        $generationConfig = array_replace_recursive(
            $providerOptions['generation_config'] ?? [],
            Value::filled($transcriptionConfig) ? ['transcription_config' => $transcriptionConfig] : [],
        );

        $body = array_merge(['store' => false], $providerOptions, array_filter([
            'model' => $model,
            'input' => [$audioBlock],
            'generation_config' => $generationConfig ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('interactions', $body),
        );

        $data = $response->getJson() ?? [];

        $steps = $data['steps'] ?? [];

        return new TranscriptionResponse(
            trim($this->extractText($steps)),
            $this->speakerSegments($steps),
            TranscriptionUsage::from($this->extractUsage($data)),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Group the word annotations Gemini returns into one segment per speaker turn.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\TranscriptionSegment>
     */
    protected function speakerSegments(array $steps): CollectionInterface
    {
        /** @var list<\Crustum\Ai\Responses\Data\TranscriptionSegment> $items */
        $items = [];

        foreach ($this->wordAnnotations($steps) as $word) {
            $text = (string)($word['text'] ?? '');
            $speaker = (string)($word['speaker'] ?? '');
            $last = end($items);

            if ($last instanceof TranscriptionSegment && $last->speaker === $speaker) {
                $last->text = trim($last->text . ' ' . $text);
                $last->endSeconds = $this->offsetToSeconds($word['end_offset'] ?? '');

                continue;
            }

            $items[] = new TranscriptionSegment(
                $text,
                $speaker,
                $this->offsetToSeconds($word['start_offset'] ?? ''),
                $this->offsetToSeconds($word['end_offset'] ?? ''),
            );
        }

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\TranscriptionSegment> $segments */
        $segments = collection($items);

        return $segments;
    }

    /**
     * Get the word annotations Gemini attached to the transcribed text.
     *
     * @param array<int, array<string, mixed>> $steps Response steps
     * @return array<int, array<string, mixed>>
     */
    protected function wordAnnotations(array $steps): array
    {
        return (new Collection($steps))
            ->unfold(fn(array $step): array => $step['content'] ?? [])
            ->unfold(fn(mixed $block): array => is_array($block) ? ($block['annotations'] ?? []) : [])
            ->filter(fn(mixed $annotation): bool => is_array($annotation) && ($annotation['type'] ?? '') === 'word_info')
            ->toList();
    }

    /**
     * Convert a Gemini duration offset, such as "1.500s", to seconds.
     *
     * @param string $offset Duration offset
     */
    protected function offsetToSeconds(string $offset): float
    {
        return $this->timestampToSeconds(rtrim($offset, 's'));
    }

    /**
     * Transcribe the given audio by asking a general purpose model for the text.
     *
     * @param \Crustum\Ai\Contracts\Providers\TranscriptionProvider $provider Transcription provider
     * @param string $model Model name
     * @param array<string, mixed> $audioBlock Audio content block
     * @param string|null $language Optional language code
     * @param bool $diarize Whether to identify speakers
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\TranscriptionResponse
     */
    protected function transcribeWithPrompt(
        TranscriptionProvider $provider,
        string $model,
        array $audioBlock,
        ?string $language,
        bool $diarize,
        int $timeout,
        array $providerOptions,
    ): TranscriptionResponse {
        if ($diarize) {
            $prompt = $language !== null
                ? "Transcribe this audio with timestamps in {$language}. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time."
                : 'Transcribe this audio with timestamps. Return the full transcript and a list of segments. Use MM:SS or HH:MM:SS timestamps, with optional fractional seconds, for start_time and end_time.';

            $response = $this->withErrorHandling(
                $provider->name(),
                fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('interactions', array_merge(['store' => false], $providerOptions, [
                    'model' => $model,
                    'input' => [['type' => 'text', 'text' => $prompt], $audioBlock],
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'transcript' => ['type' => 'string'],
                                'segments' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'text' => ['type' => 'string'],
                                            'start_time' => ['type' => 'string'],
                                            'end_time' => ['type' => 'string'],
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

            $payload = $response->getJson() ?? [];

            $data = json_decode($this->extractText($payload['steps'] ?? []) ?: '{}', true);

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
                fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('interactions', array_merge(['store' => false], $providerOptions, [
                    'model' => $model,
                    'input' => [['type' => 'text', 'text' => $prompt], $audioBlock],
                ])),
            );

            $payload = $response->getJson() ?? [];

            $text = $this->extractText($payload['steps'] ?? []);

            $segments = new Collection([]);
        }

        return new TranscriptionResponse(
            trim((string)$text),
            $segments,
            TranscriptionUsage::from($this->extractUsage($payload)),
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
