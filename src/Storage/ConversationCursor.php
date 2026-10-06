<?php
declare(strict_types=1);

namespace Crustum\Ai\Storage;

/**
 * Pagination cursor for stored conversation messages.
 *
 * Carries the id of the last message on the previous page; the next page
 * continues with messages older than that id.
 */
class ConversationCursor
{
    /**
     * @param string $lastId Id of the last message on the previous page
     */
    public function __construct(public readonly string $lastId)
    {
    }

    /**
     * Encode the cursor for transport as a query parameter.
     */
    public function encode(): string
    {
        return base64_encode((string)json_encode(['last_id' => $this->lastId]));
    }

    /**
     * Decode a transported cursor, returning null when it is malformed.
     *
     * @param string $encoded Encoded cursor
     */
    public static function decode(string $encoded): ?self
    {
        $decoded = json_decode((string)base64_decode($encoded, true), true);
        $lastId = is_array($decoded) ? ($decoded['last_id'] ?? null) : null;

        if (!is_string($lastId) || $lastId === '') {
            return null;
        }

        return new self($lastId);
    }
}
