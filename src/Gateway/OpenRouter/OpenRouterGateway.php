<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter;

use Cake\Event\EventManagerInterface;
use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\Gateway;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\EmbeddingProvider;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\SupportsWebFetch;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\PerformsChatCompletionStepsTrait;
use Crustum\Ai\Gateway\OpenRouter\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\OpenRouter\Trait\CreatesOpenRouterClientTrait;
use Crustum\Ai\Gateway\OpenRouter\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\OpenRouter\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenRouter\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\WrapsPcmAudioTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\EmbeddingsResponse;
use Crustum\Ai\Responses\ImageResponse;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Reflection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * OpenRouter API gateway.
 */
class OpenRouterGateway implements Gateway, StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesOpenRouterClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use ParsesTextResponsesTrait;
    use HandlesFailoverErrorsTrait;
    use MapsChatCompletionMessagesTrait;
    use MapsChatCompletionToolsTrait;
    use ParsesServerSentEventsTrait;
    use PerformsChatCompletionStepsTrait;
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
     * Map a provider tool to an OpenRouter tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\ProviderTool $tool Provider tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('OpenRouter does not support [' . Reflection::classBasename($tool) . '] provider tools.'),
        };
    }

    /**
     * Map a web fetch tool to an OpenRouter server tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebFetch $tool Web fetch tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     * @throws \RuntimeException When the provider does not support web fetch
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web fetch.');
        }

        return [
            'type' => 'openrouter:web_fetch',
            ...$provider->webFetchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an OpenRouter server tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $tool Web search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     * @throws \RuntimeException When the provider does not support web search
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web search.');
        }

        return [
            'type' => 'openrouter:web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
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
        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $imageConfig = array_filter([
            'aspect_ratio' => $imageOptions['aspect_ratio'] ?? null,
            'image_size' => $imageOptions['image_size'] ?? null,
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 120)
                ->post('chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $this->buildImageMessages($prompt, $attachments),
                    'modalities' => ['image'],
                    'image_config' => $imageConfig ?: null,
                ])),
        );

        $data = $response->getJson() ?? [];

        $message = $data['choices'][0]['message'] ?? [];

        $images = collection($message['images'] ?? [])->map(function (array $image): ?GeneratedImage {
            $url = $image['image_url']['url'] ?? '';

            if (preg_match('/^data:(image\/[\w+.-]+);base64,(.+)$/', $url, $matches) === 1) {
                return new GeneratedImage($matches[2], $matches[1]);
            }

            return null;
        })->filter()->toList();

        $usage = $data['usage'] ?? [];

        return new ImageResponse(
            $images,
            new Usage($usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
            new Meta($provider->name(), $data['model'] ?? $model),
        );
    }

    /**
     * Build the messages array for an image generation request.
     *
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @return array<int, array<string, mixed>>
     */
    protected function buildImageMessages(string $prompt, array $attachments): array
    {
        if ($attachments === []) {
            return [['role' => 'user', 'content' => $prompt]];
        }

        return [['role' => 'user', 'content' => array_merge(
            [['type' => 'text', 'text' => $prompt]],
            $this->mapAttachments(collection($attachments)),
        )]];
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
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $format = $this->audioResponseFormat($model);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('audio/speech', array_filter([
                'model' => $model,
                'input' => $text,
                'voice' => $this->resolveVoice($model, $voice),
                'response_format' => $format,
                'speed' => 1.0,
                'instructions' => $instructions,
            ])),
        );

        return new AudioResponse(
            base64_encode($response->getStringBody()),
            new Meta($provider->name(), $model),
            $this->audioResponseMimeType($format),
        );
    }

    /**
     * Resolve the alias voice for the given model.
     *
     * @param string $model Model name
     * @param string $voice Voice identifier
     * @return string
     */
    protected function resolveVoice(string $model, string $voice): string
    {
        if ($this->isGeminiTtsModel($model)) {
            return match ($voice) {
                'default-male' => 'Puck',
                'default-female' => 'Kore',
                default => $voice,
            };
        }

        return match ($voice) {
            'default-male' => 'ash',
            'default-female' => 'alloy',
            default => $voice,
        };
    }

    /**
     * Resolve the response_format the model accepts.
     *
     * @param string $model Model name
     * @return string
     */
    protected function audioResponseFormat(string $model): string
    {
        return $this->isGeminiTtsModel($model) ? 'pcm' : 'mp3';
    }

    /**
     * Map a response_format value to the HTTP audio MIME type.
     *
     * @param string $format Audio format
     * @return string
     */
    protected function audioResponseMimeType(string $format): string
    {
        return match ($format) {
            'mp3' => 'audio/mpeg',
            'pcm' => 'audio/pcm',
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    /**
     * Determine if the model is a Gemini TTS model.
     *
     * @param string $model Model name
     * @return bool
     */
    protected function isGeminiTtsModel(string $model): bool
    {
        return str_contains($model, 'gemini') && str_contains($model, 'tts');
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
        if ($diarize) {
            throw new LogicException(
                'OpenRouter does not support diarized transcription. Use the OpenAI, ElevenLabs, Mistral, or Gemini provider for diarization.',
            );
        }

        $mimeType = $audio->mimeType();
        $content = $audio->content();

        if ($mimeType === 'audio/pcm') {
            $content = $this->pcmToWav($content);
            $mimeType = 'audio/wav';
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                'model' => $model,
                'input_audio' => [
                    'data' => base64_encode($content),
                    'format' => $this->audioFormat($mimeType),
                ],
                'language' => $language,
            ]))),
        );

        $data = $response->getJson() ?? [];

        return new TranscriptionResponse(
            $data['text'] ?? '',
            collection([]),
            new Usage(
                (int)Hash::get($data, 'usage.input_tokens', 0),
                (int)Hash::get($data, 'usage.output_tokens', 0),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map an audio MIME type to OpenRouter's input_audio.format value.
     *
     * @param string $mimeType Audio MIME type
     * @return string
     */
    protected function audioFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/webm' => 'webm',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            default => throw new InvalidArgumentException(
                sprintf('Unsupported audio MIME type [%s] for OpenRouter transcription. Supported types: audio/wav, audio/mp3, audio/mpeg, audio/flac, audio/m4a, audio/mp4, audio/ogg, audio/webm, audio/aac.', $mimeType),
            ),
        };
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
        $body = array_merge($providerOptions, [
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions,
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('embeddings', $body),
        );

        $data = $response->getJson() ?? [];

        $this->validateTextResponse($data);

        return new EmbeddingsResponse(
            collection($data['data'] ?? [])->extract('embedding')->toList(),
            $data['usage']['prompt_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }
}
