<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Providers\Provider;
use DateInterval;
use Throwable;

/**
 * Dispatched when creating a vector store fails.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class CreatingStoreFailed extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $name Store name
     * @param string|null $description Store description
     * @param \Cake\Collection\CollectionInterface<int, string> $fileIds Initial file IDs
     * @param \DateInterval|null $expiresWhenIdleFor Idle expiration interval
     * @param \Throwable $exception The failure
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public CollectionInterface $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
        public Throwable $exception,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'name' => $name,
            'description' => $description,
            'fileIds' => $fileIds,
            'expiresWhenIdleFor' => $expiresWhenIdleFor,
            'exception' => $exception,
        ], $provider);
    }
}
