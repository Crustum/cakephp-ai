<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\Collection;
use Cake\Http\Client\FormData;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\MergesHeadersTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Http\HttpClientFactory;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\TranscriptionResponse;
use RuntimeException;

/**
 * ElevenLabs TTS and speech-to-text gateway.
 */
class ElevenLabsGateway implements AudioGateway, TranscriptionGateway
{
    use HandlesFailoverErrorsTrait;
    use MergesHeadersTrait;

    /**
     * Active provider for the pending HTTP request.
     */
    protected ?Provider $elevenLabsHttpProvider = null;

    /**
     * Timeout for the pending HTTP request.
     */
    protected int $elevenLabsHttpTimeout = 30;

    /**
     * Multipart attachments for the pending HTTP request.
     *
     * @var array<int, array{field: string, content: string, filename: string|null, headers: array<string, string>}>
     */
    protected array $elevenLabsHttpAttachments = [];

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
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $voice = match ($voice) {
            'default-male' => 'onwK4e9ZLuTAKqWW03F9',
            'default-female' => 'XrExE9yKIg1WjnnlVkGX',
            default => $voice,
        };

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)->post('text-to-speech/' . $voice, [
                'model_id' => $model,
                'text' => $text,
            ]),
        );

        return new AudioResponse(
            base64_encode((string)$response->getBody()),
            new Meta($provider->name(), $model),
            'audio/mpeg',
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), 'file', array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('speech-to-text', array_merge($providerOptions, array_filter([
                    'model_id' => $model,
                    'language' => $language,
                    'diarize' => $diarize ? 'true' : 'false',
                ]))),
        );

        $data = $response->getJson() ?? [];

        $segments = $diarize
            ? ($data['words'] ?? [])
            : [];

        $mappedSegments = (new Collection($segments))
            ->filter(fn(mixed $segment): bool => ($segment['type'] ?? '') === 'word')
            ->map(fn(array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment['text'],
                $segment['speaker_id'] ?? '',
                $segment['start'],
                $segment['end'],
            ));

        return new TranscriptionResponse(
            $data['text'] ?? '',
            $mappedSegments,
            new Usage(),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the ElevenLabs API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int $timeout Request timeout in seconds
     */
    protected function client(Provider $provider, int $timeout = 30): static
    {
        $this->elevenLabsHttpProvider = $provider;
        $this->elevenLabsHttpTimeout = $timeout;
        $this->elevenLabsHttpAttachments = [];

        return $this;
    }

    /**
     * Attach a file to the pending multipart request.
     *
     * @param string $field Form field name
     * @param string $content File content
     * @param string|null $filename File name
     * @param array<string, string> $headers Additional part headers
     */
    protected function attach(string $field, string $content, ?string $filename = null, array $headers = []): static
    {
        $this->elevenLabsHttpAttachments[] = [
            'field' => $field,
            'content' => $content,
            'filename' => $filename,
            'headers' => $headers,
        ];

        return $this;
    }

    /**
     * Send a POST request to the ElevenLabs API.
     *
     * @param string $path API path
     * @param array<string, mixed> $body Request body
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function post(string $path, array $body = []): HttpResponseInterface
    {
        $provider = $this->elevenLabsHttpProvider;

        if (!$provider instanceof Provider) {
            throw new RuntimeException('ElevenLabs HTTP provider is not configured.');
        }

        $url = rtrim($this->baseUrl($provider), '/') . '/' . ltrim($path, '/');

        $http = HttpClientFactory::create($this->elevenLabsHttpTimeout);

        if ($this->elevenLabsHttpAttachments !== []) {
            $formData = $this->buildMultipartFormData($body);
            $headers = array_merge($this->authHeaders($provider), [
                'Content-Type' => $formData->contentType(),
            ]);

            return $http->post($url, (string)$formData, [
                'headers' => $headers,
            ]);
        }

        $headers = array_merge($this->authHeaders($provider), [
            'Content-Type' => 'application/json',
            'Accept' => 'audio/mpeg',
        ]);

        return $http->post($url, json_encode($body) ?: '{}', [
            'headers' => $headers,
        ]);
    }

    /**
     * Get authorization headers for the ElevenLabs API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, string>
     */
    protected function authHeaders(Provider $provider): array
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        if ($key === null || trim($key) === '') {
            return $this->mergeConfiguredHeaders([], $provider->additionalConfiguration()['headers'] ?? []);
        }

        return $this->mergeConfiguredHeaders([
            'xi-api-key' => $key,
        ], $provider->additionalConfiguration()['headers'] ?? []);
    }

    /**
     * Get the base URL for the ElevenLabs API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim((string)($provider->additionalConfiguration()['url'] ?? 'https://api.elevenlabs.io/v1'), '/');
    }

    /**
     * Build multipart form data for file upload requests.
     *
     * @param array<string, mixed> $fields Form fields
     * @return \Cake\Http\Client\FormData
     */
    protected function buildMultipartFormData(array $fields): FormData
    {
        $formData = new FormData();

        foreach ($fields as $name => $value) {
            $formData->add($name, is_scalar($value) ? (string)$value : (json_encode($value) ?: ''));
        }

        foreach ($this->elevenLabsHttpAttachments as $attachment) {
            $part = $formData->newPart($attachment['field'], $attachment['content']);
            $part->filename($attachment['filename'] ?? 'file');
            $part->type($attachment['headers']['Content-Type'] ?? 'application/octet-stream');
            $formData->add($part);
        }

        return $formData;
    }
}
