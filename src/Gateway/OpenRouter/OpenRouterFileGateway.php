<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\OpenRouter\Trait\CreatesOpenRouterClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\PreparesStorableFilesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * OpenRouter Files API gateway.
 */
class OpenRouterFileGateway implements FileGateway
{
    use CreatesOpenRouterClientTrait;
    use HandlesFailoverErrorsTrait;
    use PreparesStorableFilesTrait;

    /**
     * Get a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider File provider
     * @param string $fileId File ID
     * @return \Crustum\Ai\Responses\FileResponse
     */
    public function getFile(FileProvider $provider, string $fileId): FileResponse
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->get('files/' . $fileId),
        );

        $data = $response->getJson() ?? [];

        return new FileResponse(
            id: (string)($data['id'] ?? $fileId),
            mimeType: $data['mime_type'] ?? null,
        );
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

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, Lab::OpenRouter);

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', $providerOptions),
        );

        $data = $response->getJson() ?? [];

        return new StoredFileResponse((string)($data['id'] ?? ''));
    }

    /**
     * Get the base URL for the Files API, which rejects the in-region endpoints with a 403.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    /**
     * Delete a file by its ID.
     *
     * @param \Crustum\Ai\Contracts\Providers\FileProvider $provider File provider
     * @param string $fileId File ID
     * @return void
     */
    public function deleteFile(FileProvider $provider, string $fileId): void
    {
        $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->delete('files/' . $fileId),
        );
    }
}
