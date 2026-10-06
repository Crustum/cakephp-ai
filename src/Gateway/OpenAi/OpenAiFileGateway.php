<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAi;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\OpenAi\Trait\CreatesOpenAiClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\PreparesStorableFilesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * OpenAI file API gateway.
 */
class OpenAiFileGateway implements FileGateway
{
    use CreatesOpenAiClientTrait;
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

        [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($file, $this->providerOptionsKey());

        $provider = $provider->withHeaders($headers);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', array_merge(
                    ['purpose' => $this->defaultPurpose()],
                    $providerOptions,
                )),
        );

        $data = $response->getJson() ?? [];

        return new StoredFileResponse((string)($data['id'] ?? ''));
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

    /**
     * Get the default purpose to use when a file does not specify one.
     *
     * @return string
     */
    protected function defaultPurpose(): string
    {
        return 'user_data';
    }

    /**
     * Get the provider key used to resolve file upload options.
     *
     * @return \Crustum\Ai\Enums\Lab
     */
    protected function providerOptionsKey(): Lab
    {
        return Lab::OpenAI;
    }
}
