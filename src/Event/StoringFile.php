<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Providers\Provider;

/**
 * Dispatched before a file is stored.
 */
class StoringFile extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File being stored
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'file' => $file,
        ]);
    }
}
