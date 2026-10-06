<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Cake\Collection\Collection;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Store;
use DateInterval;

/**
 * Dispatched after a store is created.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class StoreCreated extends AiEvent
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
     * @param \Crustum\Ai\Store $store Created store
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $name,
        public ?string $description,
        public Collection $fileIds,
        public ?DateInterval $expiresWhenIdleFor,
        public Store $store,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'name' => $name,
            'description' => $description,
            'fileIds' => $fileIds,
            'expiresWhenIdleFor' => $expiresWhenIdleFor,
            'store' => $store,
        ], $provider);
    }
}
