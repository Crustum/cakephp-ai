<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Crustum\Ai\Approvals\Approval;
use Crustum\Ai\Approvals\PendingApproval;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;

/**
 * Message helpers for tool approval pause and resume flows.
 */
trait HandlesToolApprovalsTrait
{
    /**
     * Get the unresolved tool calls in the latest assistant turn, plus the ids already answered.
     *
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @return array{0: \Cake\Collection\Collection<array-key, \Crustum\Ai\Responses\Data\ToolCall>, 1: array<int, string>}
     */
    protected function pendingToolCalls(array $messages): array
    {
        $resolved = [];

        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->toolResults as $toolResult) {
                $resolved[] = $toolResult->id;
            }
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];

            if (!$message instanceof AssistantMessage) {
                continue;
            }

            $pending = [];

            foreach ($message->toolCalls as $toolCall) {
                if ($toolCall instanceof ToolCall && !in_array($toolCall->id, $resolved, true)) {
                    $pending[] = $toolCall;
                }
            }

            return [new Collection($pending), $resolved];
        }

        return [new Collection([]), $resolved];
    }

    /**
     * Append a resume's tool results, merging into the pause turn's partial results so one assistant turn keeps one answering message.
     *
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Tool results to append
     * @return array{0: array<int, \Crustum\Ai\Messages\Message>, 1: array<int, \Crustum\Ai\Messages\Message>}
     */
    protected function appendApprovalResults(array $messages, array $toolResults): array
    {
        $last = end($messages);

        if ($last instanceof ToolResultMessage) {
            array_pop($messages);

            $toolResults = [...array_values(iterator_to_array($last->toolResults)), ...$toolResults];
        }

        $answer = new ToolResultMessage(new Collection($toolResults));

        $messages[] = $answer;

        return [$messages, [$answer]];
    }

    /**
     * Settle unresolved tool calls from abandoned pauses so the history remains replayable.
     *
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @param bool $exceptLatestAssistantTurn Leave the latest assistant turn for a resume to decide
     * @return array<int, \Crustum\Ai\Messages\Message>
     */
    protected function settleAbandonedToolCalls(array $messages, bool $exceptLatestAssistantTurn = false): array
    {
        $resolved = [];

        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->toolResults as $toolResult) {
                $resolved[$toolResult->id] = true;
            }
        }

        $bound = $exceptLatestAssistantTurn ? $this->latestAssistantTurnIndex($messages) : count($messages);

        $output = [];

        for ($index = 0, $count = count($messages); $index < $count; $index++) {
            $message = $messages[$index];

            if ($index >= $bound || !$message instanceof AssistantMessage) {
                $output[] = $message;

                continue;
            }

            $dangling = $message->toolCalls->filter(
                fn(ToolCall $toolCall): bool => !isset($resolved[$toolCall->id]),
            );
            $danglingList = array_values(iterator_to_array($dangling));

            $output[] = $message;

            if ($danglingList === []) {
                continue;
            }

            $placeholders = array_map(
                fn(ToolCall $toolCall): ToolResult => new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    'This tool call was not executed because it was not approved before the conversation continued.',
                    $toolCall->resultId,
                    denied: true,
                ),
                $danglingList,
            );

            $next = $messages[$index + 1] ?? null;

            if ($next instanceof ToolResultMessage) {
                $merged = [...array_values(iterator_to_array($next->toolResults)), ...$placeholders];
                $output[] = new ToolResultMessage(new Collection($merged));

                $index++;
            } else {
                $output[] = new ToolResultMessage(new Collection($placeholders));
            }
        }

        return $output;
    }

    /**
     * Find the index of the latest assistant message, or the message count when there is none.
     *
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Conversation messages
     * @return int
     */
    protected function latestAssistantTurnIndex(array $messages): int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ($messages[$index] instanceof AssistantMessage) {
                return $index;
            }
        }

        return count($messages);
    }

    /**
     * @param \Cake\Collection\CollectionInterface<array-key, \Crustum\Ai\Responses\Data\ToolCall> $toolCalls Pending tool calls
     * @param array<string, \Crustum\Ai\Approvals\Approval|null> $approvals Approvals keyed by tool call id
     * @return \Cake\Collection\Collection<array-key, \Crustum\Ai\Approvals\PendingApproval>
     */
    protected function pendingApprovalsFor(CollectionInterface $toolCalls, array $approvals): Collection
    {
        $items = [];

        foreach ($toolCalls as $toolCall) {
            /** @var \Crustum\Ai\Approvals\Approval|null $approval */
            $approval = $approvals[$toolCall->id] ?? null;

            $items[] = new PendingApproval(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $approval instanceof Approval ? $approval->reason : null,
            );
        }

        return new Collection($items);
    }
}
