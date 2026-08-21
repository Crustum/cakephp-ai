<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Collection\Collection;
use Crustum\Ai\Providers\Provider;
use DateInterval;

/**
 * Dispatched before a store is created.
 */
class CreatingStore extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\Collection<int, string> $fileIds File identifiers
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'name' => $name,
            'description' => $description,
            'fileIds' => $fileIds,
            'expiresWhenIdleFor' => $expiresWhenIdleFor,
        ]);
    }
}
