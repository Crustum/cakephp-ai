<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Gemini\Trait\CreatesGeminiClientTrait;
use Crustum\Ai\Gateway\Trait\EncodesResourceIdsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\PreparesStorableFilesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * Gemini Files API gateway.
 */
class GeminiFileGateway implements FileGateway
{
    use CreatesGeminiClientTrait;
    use EncodesResourceIdsTrait;
    use HandlesFailoverErrorsTrait;
    use PreparesStorableFilesTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Get a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider File provider
     * @param string $fileId File identifier
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->get($fileId),
        );

        $data = $response->getJson() ?? [];

        return (new FileResponse(
            id: $data['name'],
            mimeType: $data['mimeType'],
        ))->withRawResponse($response);
    }

    /**
     * Store the given file.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider File provider
     * @param \Crustum\Ai\Contracts\Files\StorableFile $file File to store
     * @return \Crustum\Ai\Responses\StoredFileResponse
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse {
        [$content, $mime, $name] = $this->prepareStorableFile($file);

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::Gemini);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->usingUploadBaseUrl()
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', array_replace_recursive([
                    'file' => ['display_name' => $name],
                ], $providerOptions)),
        );

        $data = $response->getJson() ?? [];

        return (new StoredFileResponse($data['file']['name']))->withRawResponse($response);
    }

    /**
     * Delete a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider File provider
     * @param string $fileId File identifier
     * @return void
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $fileId = $this->normalizeFileId($fileId);

        $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->delete($fileId),
        );
    }

    /**
     * Normalize the file ID to include the resource prefix.
     *
     * @param string $fileId File identifier
     * @return string
     */
    protected function normalizeFileId(string $fileId): string
    {
        return 'files/' . $this->resourceId($this->chopPrefix($fileId, 'files/'));
    }

    /**
     * Remove the first matching prefix from the given value.
     *
     * @param string $value Value to strip
     * @param list<string>|string $prefixes Prefix or ordered prefixes to remove
     * @return string
     */
    protected function chopPrefix(string $value, string|array $prefixes): string
    {
        foreach ((array)$prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }
}
