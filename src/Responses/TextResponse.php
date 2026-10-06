<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\TextUsage;
use Crustum\Ai\Responses\Trait\HasRawResponseTrait;
use Stringable;

/**
 * Text Response
 *
 * Represents a basic text response from an AI provider.
 */
class TextResponse implements Stringable
{
    use HasRawResponseTrait;

    /**
     * Messages in the response
     *
     * @var \Cake\Collection\CollectionInterface<array-key, \Crustum\Ai\Messages\Message>
     */
    public CollectionInterface $messages;

    /**
     * Tool calls made during generation
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall>
     */
    public CollectionInterface $toolCalls;

    /**
     * Tool results from executed tools
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult>
     */
    public CollectionInterface $toolResults;

    /**
     * Steps taken during generation
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step>
     */
    public CollectionInterface $steps;

    /**
     * Pending tool approvals that pause the response
     *
     * @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval>
     */
    public CollectionInterface $pendingApprovals;

    /**
     * Reasoning the model produced before answering, if any
     */
    public string $reasoning = '';

    /**
     * Constructor
     *
     * @param string $text The generated text
     * @param \Crustum\Ai\Responses\Data\TextUsage $usage Token usage information
     * @param \Crustum\Ai\Responses\Data\Meta $meta Metadata about the response
     */
    public function __construct(public string $text, public TextUsage $usage, public Meta $meta)
    {
        $this->messages = collection([]);
        $this->toolCalls = collection([]);
        $this->toolResults = collection([]);
        $this->steps = collection([]);
        $this->pendingApprovals = collection([]);
    }

    /**
     * Provide the message context for the response.
     *
     * @param \Cake\Collection\CollectionInterface<array-key, \Crustum\Ai\Messages\Message> $messages
     */
    public function withMessages(CollectionInterface $messages): static
    {
        $this->messages = $messages;

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls */
        $toolCalls = $this->messages
            ->filter(fn($message): bool => $message instanceof AssistantMessage)
            ->map(fn($message) => $message->toolCalls)
            ->reduce(
                fn(Collection $carry, CollectionInterface $item): CollectionInterface => $carry->append($item->toList()),
                collection([]),
            );

        /** @var \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults */
        $toolResults = $this->messages
            ->filter(fn($message): bool => $message instanceof ToolResultMessage)
            ->map(fn($message) => $message->toolResults)
            ->reduce(
                fn(Collection $carry, CollectionInterface $item): CollectionInterface => $carry->append($item->toList()),
                collection([]),
            );

        $this->withToolCallsAndResults($toolCalls, $toolResults);

        return $this;
    }

    /**
     * Provide the tool calls and results for the message.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults
     */
    public function withToolCallsAndResults(CollectionInterface $toolCalls, CollectionInterface $toolResults): static
    {
        $this->toolCalls = collection($toolCalls->reject(
            fn($toolCall): bool => $toolCall->name === 'output_structured_data',
        )->toList());

        $this->toolResults = collection($toolResults->toList());

        return $this;
    }

    /**
     * Provide the steps taken to generate the response.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\Step> $steps
     */
    public function withSteps(CollectionInterface $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    /**
     * Provide the reasoning emitted across every step of the response.
     *
     * @param string $reasoning Reasoning text
     */
    public function withReasoning(string $reasoning): static
    {
        $this->reasoning = $reasoning;

        return $this;
    }

    /**
     * Mark the response as waiting for tool approval.
     *
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals
     */
    public function withPendingApprovals(CollectionInterface $pendingApprovals): static
    {
        $this->pendingApprovals = collection($pendingApprovals->toList());

        return $this;
    }

    /**
     * Determine whether the response has tool calls pending approval.
     *
     * @return bool
     */
    public function hasPendingApprovals(): bool
    {
        return !$this->pendingApprovals->isEmpty();
    }

    /**
     * Get the string representation of the object.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
