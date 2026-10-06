<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Groq;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\TranscribableAudio;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Contracts\Providers\SupportsCodeExecution;
use Crustum\Ai\Contracts\Providers\SupportsWebSearch;
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
use Crustum\Ai\Providers\Tools\CodeExecution;
use Crustum\Ai\Providers\Tools\ProviderTool;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TranscriptionSegment;
use Crustum\Ai\Responses\Data\TranscriptionUsage;
use Crustum\Ai\Responses\TranscriptionResponse;
use Crustum\Ai\Utility\Reflection;
use LogicException;
use RuntimeException;

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
     * Map a provider tool to a Groq built-in tool definition.
     *
     * @param \Crustum\Ai\Providers\Tools\ProviderTool $tool Provider tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof CodeExecution => $this->mapCodeExecutionTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('Groq does not support [' . Reflection::classBasename($tool) . '] provider tools.'),
        };
    }

    /**
     * Map a code execution tool to a Groq code interpreter definition.
     *
     * @param \Crustum\Ai\Providers\Tools\CodeExecution $tool Code execution tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapCodeExecutionTool(CodeExecution $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsCodeExecution) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support code execution.');
        }

        return [
            'type' => 'code_interpreter',
            ...$provider->codeExecutionToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to a Groq browser search definition.
     *
     * @param \Crustum\Ai\Providers\Tools\WebSearch $tool Web search tool
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return array<string, mixed>
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (!$provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider [' . $provider->name() . '] does not support web search.');
        }

        return [
            'type' => 'browser_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }

    /**
     * The status codes that indicate Groq is transiently unavailable and the request should fail over.
     *
     * The status codes Groq documents as transient: 498 is "flex tier capacity exceeded", alongside 502 and 503.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [498, 502, 503];
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
                    'response_format' => $providerOptions['response_format'] ?? 'verbose_json',
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
            new TranscriptionUsage(audioSeconds: $data['duration'] ?? null),
            new Meta($provider->name(), $model),
        );
    }
}
