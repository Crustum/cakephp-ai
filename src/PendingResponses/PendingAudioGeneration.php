<?php
declare(strict_types=1);

namespace Crustum\Ai\PendingResponses;

use BackedEnum;
use Cake\Core\Configure;
use Cake\Event\EventManager;
use Crustum\Ai\Ai;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\ProviderFailedOver;
use Crustum\Ai\Exception\FailoverableException;
use Crustum\Ai\Job\GenerateAudioJob;
use Crustum\Ai\Job\PendingDispatch;
use Crustum\Ai\PendingResponses\Trait\ResolvesProviderOptionsTrait;
use Crustum\Ai\Prompts\QueuedAudioPrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\AudioResponse;
use Crustum\Ai\Responses\QueuedAudioResponse;
use Crustum\Ai\Trait\ConditionableTrait;
use InvalidArgumentException;

/**
 * Pending audio generation request.
 *
 * Builder for configuring and executing audio generation requests.
 * Supports voice selection, instructions, timeout configuration,
 * and both synchronous and queued execution.
 */
class PendingAudioGeneration
{
    use ConditionableTrait;
    use ResolvesProviderOptionsTrait;

    /**
     * The voice for the generated audio.
     */
    protected string $voice = 'default-female';

    /**
     * Free-form instructions guiding how the audio should sound.
     */
    protected ?string $instructions = null;

    /**
     * The timeout (in seconds) for the audio generation.
     */
    protected int $timeout = 30;

    /**
     * Constructor.
     *
     * @param string $text The text to convert to audio
     * @throws \InvalidArgumentException if text is blank
     */
    public function __construct(
        protected string $text,
    ) {
        if (empty(trim($text))) {
            throw new InvalidArgumentException('Text content is required to generate audio.');
        }
    }

    /**
     * Specify a specific voice for the generated audio.
     *
     * @param \BackedEnum|string $voice The voice identifier
     */
    public function voice(BackedEnum|string $voice): static
    {
        $this->voice = $voice instanceof BackedEnum ? (string)$voice->value : $voice;

        return $this;
    }

    /**
     * Indicate that the voice should be male.
     */
    public function male(): static
    {
        $this->voice = 'default-male';

        return $this;
    }

    /**
     * Indicate that the voice should be female.
     */
    public function female(): static
    {
        $this->voice = 'default-female';

        return $this;
    }

    /**
     * Provide free-form instructions guiding how the audio should sound.
     *
     * @param string $instructions Voice instructions
     */
    public function instructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the audio generation.
     *
     * @param int $seconds Timeout in seconds
     */
    public function timeout(int $seconds = 30): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the audio.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\AudioResponse
     * @throws \Crustum\Ai\Exception\FailoverableException if every configured provider fails to generate the audio.
     */
    public function generate(Lab|array|string|null $provider = null, ?string $model = null): AudioResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? Configure::read('Ai.default_for_audio'),
            $model,
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::manager()->fakeableAudioProvider($provider);

            $model ??= $provider->defaultAudioModel();

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $provider = $provider->withHeaders($headers);

            try {
                return $provider->audio(
                    $this->text,
                    $this->voice,
                    $this->instructions,
                    $model,
                    $this->timeout,
                    $providerOptions,
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                EventManager::instance()->dispatch(new ProviderFailedOver($provider->name(), $model, $e, $provider));

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of the audio.
     *
     * @param \Crustum\Ai\Enums\Lab|array<string, string>|string|null $provider Provider or array of providers
     * @param string|null $model The model to use
     * @return \Crustum\Ai\Responses\QueuedAudioResponse
     */
    public function queue(Lab|array|string|null $provider = null, ?string $model = null): QueuedAudioResponse
    {
        if (Ai::manager()->audioIsFaked()) {
            Ai::manager()->recordAudioGeneration(
                new QueuedAudioPrompt(
                    $this->text,
                    $this->voice,
                    $this->instructions,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                ),
            );
        }

        return new QueuedAudioResponse(
            new PendingDispatch(
                GenerateAudioJob::class,
                GenerateAudioJob::payload($this, $provider, $model),
            ),
        );
    }
}
