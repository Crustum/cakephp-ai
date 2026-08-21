<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts\Gateway;

use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\StepContext;
use Crustum\Ai\Gateway\StepResponse;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Generator;

/**
 * Step Text Gateway Interface
 *
 * Defines methods for generating text in a step-based conversation.
 */
interface StepTextGateway
{
    /**
     * Generate text for a single step in a conversation.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The text provider instance
     * @param string $model The model to use for text generation
     * @param string|null $instructions System instructions for the model
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools for the model
     * @param array<string, \Crustum\JsonSchema\Types\Type>|null $schema Response schema definition
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Text generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Context for this conversation step
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
    ): StepResponse;

    /**
     * Stream text for a single step in a conversation.
     *
     * @param string $invocationId Unique identifier for this invocation
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The text provider instance
     * @param string $model The model to use for text generation
     * @param string|null $instructions System instructions for the model
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Contracts\Tool> $tools Available tools for the model
     * @param array<string, \Crustum\JsonSchema\Types\Type>|null $schema Response schema definition
     * @param \Crustum\Ai\Gateway\TextGenerationOptions|null $options Text generation options
     * @param int|null $timeout Timeout in seconds
     * @param \Crustum\Ai\Gateway\StepContext $stepContext Context for this conversation step
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
    ): Generator;
}
