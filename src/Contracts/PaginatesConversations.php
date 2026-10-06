<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

use Crustum\Ai\Storage\ConversationCursor;
use Crustum\Ai\Storage\ConversationMessagePage;

/**
 * Conversation store with message pagination.
 */
interface PaginatesConversations
{
    /**
     * Paginate the given conversation's messages, newest first.
     *
     * @param string $conversationId Conversation identifier
     * @param int $perPage Messages per page
     * @param string $cursorName Query parameter carrying the cursor
     * @param \Crustum\Ai\Storage\ConversationCursor|string|null $cursor Cursor, read from the current request when null
     */
    public function paginateConversationMessages(
        string $conversationId,
        int $perPage = 15,
        string $cursorName = 'cursor',
        ConversationCursor|string|null $cursor = null,
    ): ConversationMessagePage;
}
