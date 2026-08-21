<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway;

use Closure;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Files;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;
use RuntimeException;

/**
 * Fake File Gateway
 *
 * Fake implementation of file gateway for testing purposes.
 * Allows simulating file operations without making actual API calls.
 */
class FakeFileGateway implements FileGateway
{
    /**
     * Current response index for array responses
     */
    protected int $currentResponseIndex = 0;

    /**
     * Whether to prevent operations without fake responses
     */
    protected bool $preventStrayOperations = false;

    /**
     * Constructor.
     *
     * @param \Closure|array $responses Responses to return
     */
    public function __construct(protected Closure|array $responses = [])
    {
    }

    /**
     * Get a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param string $fileId The file identifier
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(
        FileProvider $provider,
        string $fileId,
    ): FileResponse {
        return $this->nextGetResponse($fileId);
    }

    /**
     * Get the next response for a get request.
     *
     * @param string $fileId The file ID
     * @return \Crustum\Ai\Responses\FileResponse
     */
    protected function nextGetResponse(string $fileId): FileResponse
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $fileId);

        $result = $this->marshalGetResponse($response, $fileId);
        $this->currentResponseIndex++;

        return $result;
    }

    /**
     * Marshal the given response into a FileResponse instance.
     *
     * @param mixed $response The response to marshal
     * @param string $fileId The file ID
     * @return \Crustum\Ai\Responses\FileResponse
     */
    protected function marshalGetResponse(mixed $response, string $fileId): FileResponse
    {
        if ($response instanceof Closure) {
            $response = $response($fileId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted file retrieval without a fake response.');
            }

            return new FileResponse($fileId, mimeType: 'text/plain', content: 'fake-content');
        }

        if (is_string($response)) {
            return new FileResponse($fileId, mimeType: 'text/plain', content: $response);
        }

        return $response;
    }

    /**
     * Store the given file.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file The file to store
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse {
        return new StoredFileResponse(Files::fakeId($file->name() ?? $file->content()));
    }

    /**
     * Delete a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider The file provider instance
     * @param string $fileId The file identifier to delete
     * @return void
     */
    public function deleteFile(
        FileProvider $provider,
        string $fileId,
    ): void {
        // Fake implementation - no-op
    }

    /**
     * Indicate that an exception should be thrown if any file operation is not faked.
     *
     * @param bool $prevent Whether to prevent stray operations
     */
    public function preventStrayOperations(bool $prevent = true): static
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
