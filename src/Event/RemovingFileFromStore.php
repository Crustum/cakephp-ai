<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before a file is removed from a store.
 */
class RemovingFileFromStore extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $storeId Store identifier
     * @param string $documentId Provider document identifier
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $documentId,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'storeId' => $storeId,
            'documentId' => $documentId,
        ]);
    }
}
