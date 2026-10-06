<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * Dispatched after a file is stored.
 *
 * @extends \Crustum\Ai\Event\AiEvent<\Crustum\Ai\Providers\Provider>
 */
class FileStored extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file Stored file
     * @param \Crustum\Ai\Responses\StoredFileResponse $response Stored file response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public StorableFile $file,
        public StoredFileResponse $response,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'file' => $file,
            'response' => $response,
        ], $provider);
    }
}
