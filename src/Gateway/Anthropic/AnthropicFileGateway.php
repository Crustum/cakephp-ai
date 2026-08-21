<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Contracts\Gateway\FileGateway;
use Crustum\Ai\Contracts\Providers\FileProvider;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\Anthropic\Trait\CreatesAnthropicClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\PreparesStorableFilesTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\FileResponse;
use Crustum\Ai\Responses\StoredFileResponse;

/**
 * Anthropic Files API gateway.
 */
class AnthropicFileGateway implements FileGateway
{
    use CreatesAnthropicClientTrait;
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->get('files/' . $fileId),
        );

        $data = $response->getJson() ?? [];

        return new FileResponse(
            id: $data['id'],
            mimeType: $data['mime_type'],
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

        $providerOptions = $this->resolveProviderOptions($file, Lab::Anthropic);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)
                ->attach('file', $content, $name, ['Content-Type' => $mime])
                ->post('files', $providerOptions),
        );

        $data = $response->getJson() ?? [];

        return new StoredFileResponse($data['id']);
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
        $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider)->delete('files/' . $fileId),
        );
    }

    /**
     * The status codes that indicate a provider is overloaded.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        // 529 is Anthropic's own "overloaded" status, plus the shared transient gateway and Cloudflare codes.
        return [529, 502, 503, 504, 520, 522, 524];
    }
}
