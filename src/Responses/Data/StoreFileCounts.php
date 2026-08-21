<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

use JsonSerializable;

/**
 * Store file counts data.
 *
 * Represents the count of files in different states within a store.
 */
class StoreFileCounts implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param int $completed Number of completed files
     * @param int $pending Number of pending files
     * @param int $failed Number of failed files
     */
    public function __construct(
        public readonly int $completed,
        public readonly int $pending,
        public readonly int $failed,
    ) {
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'pending' => $this->pending,
            'failed' => $this->failed,
        ];
    }

    /**
     * Get the JSON serializable representation.
     *
     * @return array<string, int>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
