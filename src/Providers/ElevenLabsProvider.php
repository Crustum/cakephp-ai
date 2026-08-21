<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers;

use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\AudioGateway;
use Crustum\Ai\Contracts\Gateway\TranscriptionGateway;
use Crustum\Ai\Contracts\Providers\AudioProvider;
use Crustum\Ai\Contracts\Providers\TranscriptionProvider;
use Crustum\Ai\Gateway\ElevenLabsGateway;
use Crustum\Ai\Providers\Trait\GeneratesAudioTrait;
use Crustum\Ai\Providers\Trait\GeneratesTranscriptionsTrait;
use Crustum\Ai\Providers\Trait\HasAudioGatewayTrait;
use Crustum\Ai\Providers\Trait\HasTranscriptionGatewayTrait;

/**
 * ElevenLabs AI provider.
 */
class ElevenLabsProvider extends Provider implements AudioProvider, TranscriptionProvider
{
    use GeneratesAudioTrait;
    use GeneratesTranscriptionsTrait;
    use HasAudioGatewayTrait;
    use HasTranscriptionGatewayTrait;

    /**
     * Provider configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Event manager instance.
     */
    protected EventManagerInterface $events;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Provider configuration
     * @param \Cake\Event\EventManagerInterface|null $events Event manager instance
     */
    public function __construct(
        array $config,
        ?EventManagerInterface $events = null,
    ) {
        $this->config = $config;
        $this->events = $events ?? EventManager::instance();
        $this->config['name'] ??= 'eleven';
        $this->config['driver'] ??= 'eleven';
        $this->config['key'] ??= $this->config['apiKey'] ?? null;
    }

    /**
     * Get the provider's audio gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\AudioGateway
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new ElevenLabsGateway();
    }

    /**
     * Get the provider's transcription gateway.
     *
     * @return \Crustum\Ai\Contracts\Gateway\TranscriptionGateway
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new ElevenLabsGateway();
    }

    /**
     * Get the name of the default audio (TTS) model.
     *
     * @return string
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'eleven_multilingual_v2';
    }

    /**
     * Get the name of the default transcription (STT) model.
     *
     * @return string
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'scribe_v2';
    }
}
