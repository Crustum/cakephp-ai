<?php
declare(strict_types=1);

namespace Crustum\Ai\Storage;

/**
 * One page of stored conversation messages, newest first.
 */
class ConversationMessagePage
{
    /**
     * @param list<\Crustum\Ai\Storage\StoredMessage> $items Page items
     * @param \Crustum\Ai\Storage\ConversationCursor|null $nextCursor Cursor for the next page, if any
     */
    public function __construct(
        protected array $items,
        protected ?ConversationCursor $nextCursor = null,
    ) {
    }

    /**
     * Page items, newest first.
     *
     * @return list<\Crustum\Ai\Storage\StoredMessage>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Cursor for the next page, null when this is the last page.
     */
    public function nextCursor(): ?ConversationCursor
    {
        return $this->nextCursor;
    }
}
