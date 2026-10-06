<?php
declare(strict_types=1);

namespace Crustum\Ai\Files\Trait;

use Crustum\Ai\Files\UntrustedUrl;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use RuntimeException;

/**
 * Has remote content trait.
 *
 * Provides HTTP-based content retrieval for remote files.
 */
trait HasRemoteContentTrait
{
    protected ?HttpResponseInterface $response = null;

    /**
     * Get the raw representation of the file.
     *
     * @return string
     */
    public function content(): string
    {
        return (string)$this->response()->getBody();
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return $this->name ?? basename(is_string($path) ? $path : '');
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        if ($this->mime) {
            return $this->mime;
        }

        $contentType = $this->response()->getHeaderLine('Content-Type');
        $parts = explode(';', (string)$contentType);

        return trim($parts[0]) ?: null;
    }

    /**
     * Get the declared MIME type without fetching the remote resource.
     *
     * @return string|null
     */
    public function declaredMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the HTTP HttpResponseInterface for the remote file.
     *
     * @return \Crustum\Ai\Http\Contract\HttpResponseInterface
     */
    protected function response(): HttpResponseInterface
    {
        if ($this->response === null) {
            $response = UntrustedUrl::fetch($this->url);

            if (!$response->isOk()) {
                throw new RuntimeException(sprintf(
                    'Failed to fetch remote file [%s]: HTTP %d',
                    $this->url,
                    $response->getStatusCode(),
                ));
            }

            $this->response = $response;
        }

        return $this->response;
    }

    /**
     * Get string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
