<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Groq;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\Groq\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\Groq\Trait\CreatesGroqClientTrait;
use Crustum\Ai\Gateway\Groq\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionMessagesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\PerformsChatCompletionStepsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;
use Crustum\Ai\Gateway\Trait\ResolvesAudioFilenamesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\TranscriptionResponse;
use LogicException;

/**
 * Groq Chat Completions and Transcription API gateway.
 */
class GroqGateway implements StepTextGateway, TranscriptionGateway
{
    use BuildsTextRequestsTrait;
    use CreatesGroqClientTrait;
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
     * @throws \LogicException When diarization is requested
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
                'Groq does not support diarized transcription. Use the OpenAI, ElevenLabs, Mistral, or Gemini provider for diarization.',
            );
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout)
                ->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
                ->post('audio/transcriptions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'language' => $language,
                    'response_format' => $providerOptions['response_format'] ?? 'json',
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
            new Usage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        );
    }
}
