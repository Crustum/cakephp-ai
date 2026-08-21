<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Providers\Provider;

/**
 * Dispatched after a store is deleted.
 */
class StoreDeleted extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $storeId Deleted store identifier
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'storeId' => $storeId,
        ]);
    }
}
