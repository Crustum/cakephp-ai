<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Providers\Provider;
use Throwable;

/**
 * Dispatched when storing a file fails.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class StoringFileFailed extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to store
     * @param \Throwable $exception The failure
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public Throwable $exception,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'file' => $file,
            'exception' => $exception,
        ], $provider);
    }
}
