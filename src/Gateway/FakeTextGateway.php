<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Cake\Collection\CollectionInterface;
use Closure;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Gateway\Fake\SchemaDataGenerator;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\FinishReason;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\StructuredTextResponse;
use Crustum\Ai\Responses\TextResponse;
use Crustum\Ai\Streaming\Event\StreamStart;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Streaming\Event\TextEnd;
use Crustum\Ai\Streaming\Event\TextStart;
use Crustum\Ai\Streaming\Event\ToolCall as ToolCallEvent;
use Crustum\JsonSchema\Types\ObjectType;
use Generator;
use RuntimeException;

/**
 * Fake Text Gateway
 *
 * Fake implementation of text gateway for testing purposes.
 * Allows simulating text generation without making actual API calls.
 */
class FakeTextGateway implements StepTextGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent prompts without fake responses
     */
    protected bool $preventStrayPrompts = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses)
    {
    }

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
    ): StepResponse {
        return $this->nextStep($provider, $model, $messages, $schema);
    }

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
    ): Generator {
        $step = $this->nextStep($provider, $model, $messages, $schema);

        $messageId = $this->generateUlid();

        yield (new StreamStart($this->generateUlid(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (!empty($step->text)) {
            yield (new TextStart($this->generateUlid(), $messageId, time()))->withInvocationId($invocationId);

            $words = explode(' ', $step->text);
            foreach ($words as $index => $word) {
                yield (new TextDelta(
                    $this->generateUlid(),
                    $messageId,
                    $index > 0 ? ' ' . $word : $word,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield (new TextEnd($this->generateUlid(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($step->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateUlid(), $toolCall, time()))->withInvocationId($invocationId);
        }

        return $step;
    }

    /**
     * Resolve the next fake response and marshal it into a step response.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The provider
     * @param string $model The model
     * @param array<int, \Crustum\Ai\Messages\Message> $messages The messages
     * @param array<string, \Crustum\JsonSchema\Types\Type>|null $schema The schema
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $collection = collection($messages);
        $message = $collection->filter(fn($message): bool => $message instanceof UserMessage)->last();

        $prompt = '';
        $attachments = collection([]);

        if ($message instanceof UserMessage) {
            $prompt = $message->content;
            $attachments = $message->attachments;
        }

        $response = $this->nextResponse($provider, $model, $prompt, $attachments, $schema);

        return $this->toStepResponse($response, $provider, $model)
            ->withRawResponse($response instanceof TextResponse ? $response->raw : null);
    }

    /**
     * Convert a marshalled fake response into a step response for the generation loop.
     *
     * @param mixed $response The marshalled response
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The provider
     * @param string $model The model
     * @return \Crustum\Ai\Gateway\StepResponse
     */
    protected function toStepResponse(mixed $response, TextProvider $provider, string $model): StepResponse
    {
        if ($response instanceof ToolCall) {
            return new StepResponse(
                '',
                [$response],
                FinishReason::ToolCalls,
                new Usage(),
                new Meta($provider->name(), $model),
            );
        }

        if ($response instanceof StructuredTextResponse) {
            return new StepResponse(
                $response->text,
                [],
                FinishReason::Stop,
                $response->usage,
                $response->meta,
                $response->structured,
            );
        }

        if ($response instanceof TextResponse && $response->hasPendingApprovals()) {
            return new StepResponse(
                $response->text,
                [],
                FinishReason::Stop,
                $response->usage,
                $response->meta,
                pendingApprovals: $response->pendingApprovals->toList(),
            );
        }

        return new StepResponse(
            $response->text,
            [],
            FinishReason::Stop,
            $response->usage,
            $response->meta,
        );
    }

    /**
     * Get the next response instance.
     *
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The provider
     * @param string $model The model
     * @param string $prompt The prompt
     * @param \Cake\Collection\CollectionInterface $attachments The attachments
     * @param array<string, \Crustum\JsonSchema\Types\Type>|null $schema The schema
     */
    protected function nextResponse(
        TextProvider $provider,
        string $model,
        string $prompt,
        CollectionInterface $attachments,
        ?array $schema,
    ): mixed {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt, $attachments, $provider, $model);

        $result = $this->marshalResponse($response, $provider, $model, $prompt, $attachments, $schema);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a full response instance.
     *
     * @param mixed $response The response to marshal
     * @param \Crustum\Ai\Contracts\Providers\TextProvider $provider The provider
     * @param string $model The model
     * @param string $prompt The prompt
     * @param \Cake\Collection\CollectionInterface $attachments The attachments
     * @param array<string, \Crustum\JsonSchema\Types\Type>|null $schema The schema
     */
    protected function marshalResponse(
        mixed $response,
        TextProvider $provider,
        string $model,
        string $prompt,
        CollectionInterface $attachments,
        ?array $schema,
    ): mixed {
        if (is_null($response)) {
            if ($this->preventStrayPrompts) {
                $words = array_slice(explode(' ', $prompt), 0, 10);
                throw new RuntimeException('Attempted prompt [' . implode(' ', $words) . '] without a fake agent response.');
            }

            $response = is_null($schema)
                ? 'Fake response for prompt: ' . implode(' ', array_slice(explode(' ', $prompt), 0, 10))
                : SchemaDataGenerator::generate(new ObjectType($schema));
        }

        return match (true) {
            is_string($response) => new TextResponse(
                $response,
                new Usage(), new Meta($provider->name(), $model),
            ),
            is_array($response) => new StructuredTextResponse(
                $response,
                json_encode($response), new Usage(), new Meta($provider->name(), $model),
            ),
            $response instanceof Closure => $this->marshalResponse(
                $response($prompt, $attachments, $provider, $model),
                $provider,
                $model,
                $prompt,
                $attachments,
                $schema,
            ),
            default => $response,
        };
    }

    /**
     * Generate a ULID.
     *
     * @return string
     */
    protected function generateUlid(): string
    {
        return sprintf(
            '%08x%04x%04x%04x%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffffffffffff),
        );
    }

    /**
     * Indicate that an exception should be thrown if any prompt is not faked.
     *
     * @param bool $prevent Whether to prevent stray prompts
     */
    public function preventStrayPrompts(bool $prevent = true): static
    {
        $this->preventStrayPrompts = $prevent;

        return $this;
    }
}
