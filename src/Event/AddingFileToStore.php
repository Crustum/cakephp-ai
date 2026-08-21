<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before a file is added to a store.
 */
class AddingFileToStore extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $storeId Store identifier
     * @param string $fileId File identifier
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $storeId,
        public string $fileId,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'storeId' => $storeId,
            'fileId' => $fileId,
        ]);
    }
}
