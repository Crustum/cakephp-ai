<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Gateway;

use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Generator;

class TextGenerationLoopFakeGateway implements StepTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /**
     * @var StepContext[]
     */
    public array $contexts = [];

    /**
     * @var array<int, array<int, \Crustum\Ai\Messages\Message>>
     */
    public array $messages = [];

    /**
     * @var array<int, array<int, mixed>>
     */
    public array $tools = [];

    public function __construct(
        public array $steps = [],
        public array $streams = [],
    ) {
    }

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
        $this->generateCalls++;
        $this->contexts[] = $stepContext;
        $this->messages[] = $messages;
        $this->tools[] = $tools;

        return array_shift($this->steps);
    }

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
        $this->streamCalls++;
        $this->contexts[] = $stepContext;
        $this->tools[] = $tools;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}
