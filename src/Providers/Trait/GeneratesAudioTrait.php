<?php
declare(strict_types=1);

namespace Crustum\Ai\Providers\Trait;

use Cake\Utility\Text;
use Crustum\Ai\Ai;
use Crustum\Ai\Event\AudioGenerated;
use Crustum\Ai\Event\GeneratingAudio;
use Crustum\Ai\Prompts\AudioPrompt;
use Crustum\Ai\Responses\AudioResponse;

/**
 * Generates audio through the provider's audio gateway.
 */
trait GeneratesAudioTrait
{
    /**
     * Generate audio from the given text.
     *
     * @param string $text Text to synthesize
     * @param string $voice Voice identifier
     * @param string|null $instructions Optional voice instructions
     * @param string|null $model Model name
     * @param int $timeout Timeout in seconds
     * @param array<string, mixed> $providerOptions Provider-specific options
     * @return \Crustum\Ai\Responses\AudioResponse
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $invocationId = Text::uuid();

        $model ??= $this->defaultAudioModel();

        $prompt = new AudioPrompt($text, $voice, $instructions, $this, $model, $timeout, $providerOptions);

        if (Ai::manager()->audioIsFaked()) {
            Ai::manager()->recordAudioGeneration($prompt);
        }

        $this->events->dispatch(new GeneratingAudio(
            $invocationId,
            $this,
            $model,
            $prompt,
        ));

        $response = $this->audioGateway()->generateAudio(
            $this,
            $model,
            $prompt->text,
            $prompt->voice,
            $prompt->instructions,
            $timeout,
            $prompt->providerOptions,
        );

        $this->events->dispatch(new AudioGenerated(
            $invocationId,
            $this,
            $model,
            $prompt,
            $response,
        ));

        return $response;
    }
}
