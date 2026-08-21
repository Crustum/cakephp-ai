<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

/**
 * Result of resuming a paused tool-approval turn.
 */
class ApprovalResumption
{
    /**
     * @param array<int, \Crustum\Ai\Messages\Message> $messages Updated message history
     * @param array<int, \Crustum\Ai\Messages\Message> $newMessages Messages appended during resume
     * @param array<int, \Crustum\Ai\Responses\Data\ToolResult> $results Resolved tool results
     * @param array<int, string> $failedToolCallIds Tool call ids that failed during execution
     * @param bool $shouldContinue Whether the text generation loop should continue
     */
    public function __construct(
        public array $messages,
        public array $newMessages,
        public array $results,
        public array $failedToolCallIds,
        public bool $shouldContinue,
    ) {
    }
}
