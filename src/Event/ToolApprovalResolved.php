<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Agent;

/**
 * Dispatched after tool approval decisions are resolved.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Contracts\Agent>
 */
class ToolApprovalResolved extends AiEvent
{
    /**
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Contracts\Agent $agent Agent instance
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Responses\Data\ToolResult> $toolResults Resolved tool results
     * @param string|null $conversationId Conversation identifier
     * @param object|null $conversationUser Conversation participant
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public CollectionInterface $toolResults,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'agent' => $agent,
            'toolResults' => $toolResults,
            'conversationId' => $conversationId,
            'conversationUser' => $conversationUser,
        ], $agent);
    }
}
