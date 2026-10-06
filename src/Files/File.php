<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Closure;
use Crustum\Ai\Contracts\Files\HasName;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Enums\Lab;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * File
 *
 * Abstract base class for file attachments that can be sent to AI providers.
 */
abstract class File implements HasName, HasProviderOptions
{
    /**
     * @var string|null The displayable name of the file.
     */
    public ?string $name = null;

    /**
     * @var string|null The MIME type of the file.
     */
    public ?string $mime = null;

    /**
     * @var \Laravel\SerializableClosure\SerializableClosure|array<string, string>  Request HTTP headers.
     */
    protected array|SerializableClosure $headers = [];

    /**
     * @var \Laravel\SerializableClosure\SerializableClosure|array<string, mixed>  Provider-specific options.
     */
    protected array|SerializableClosure $providerOptions = [];

    /**
     * Reconstruct a file instance from its array representation.
     *
     * @param array<string, mixed> $data The serialized file data.
     * @return \Crustum\Ai\Files\File|null The reconstructed file or null if type is invalid.
     */
    public static function fromArray(array $data): ?File
    {
        $type = $data['type'] ?? null;

        if (!is_string($type)) {
            return null;
        }

        $file = match ($type) {
            'base64-image' => new Base64Image(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-image' => new LocalImage(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-image' => new StoredImage(self::value($data, 'path', $type), self::filesystemName($data)),
            'remote-image' => new RemoteImage(self::value($data, 'url', $type), $data['mime'] ?? null),
            'provider-image' => new ProviderImage(self::value($data, 'id', $type)),
            'base64-document' => new Base64Document(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-document' => new LocalDocument(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-document' => new StoredDocument(self::value($data, 'path', $type), self::filesystemName($data)),
            'remote-document' => new RemoteDocument(self::value($data, 'url', $type), $data['mime'] ?? null),
            's3-document' => new S3Document(
                self::value($data, 'url', $type),
                isset($data['bucket_owner']) ? (string)$data['bucket_owner'] : null,
                $data['mime'] ?? null,
            ),
            'provider-document' => new ProviderDocument(self::value($data, 'id', $type)),
            'base64-audio' => new Base64Audio(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-audio' => new LocalAudio(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-audio' => new StoredAudio(self::value($data, 'path', $type), self::filesystemName($data)),
            'remote-audio' => new RemoteAudio(self::value($data, 'url', $type), $data['mime'] ?? null),
            'base64-video' => new Base64Video(self::value($data, 'base64', $type), $data['mime'] ?? null),
            'local-video' => new LocalVideo(self::value($data, 'path', $type), $data['mime'] ?? null),
            'stored-video' => new StoredVideo(self::value($data, 'path', $type), self::filesystemName($data)),
            'remote-video' => new RemoteVideo(self::value($data, 'url', $type), $data['mime'] ?? null),
            default => null,
        };

        if ($file !== null && array_key_exists('name', $data)) {
            $file->as($data['name']);
        }

        return $file;
    }

    /**
     * Extract and validate a value from array data.
     *
     * @param array<string, mixed> $data The data array.
     * @param string $key The key to extract.
     * @param string $type The type name for error messages.
     * @return string The extracted value.
     * @throws \InvalidArgumentException If the key is missing.
     */
    protected static function value(array $data, string $key, string $type): string
    {
        if (!isset($data[$key])) {
            throw new InvalidArgumentException(sprintf('Cannot reconstruct [%s] attachment because [%s] is missing or invalid.', $type, $key));
        }

        return $data[$key];
    }

    /**
     * Resolve the named filesystem from stored-file array data.
     *
     * Accepts legacy `disk` for older serialized attachments.
     *
     * @param array<string, mixed> $data The data array.
     * @return string|null
     */
    protected static function filesystemName(array $data): ?string
    {
        $name = $data['filesystem'] ?? $data['disk'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Get the displayable name of the file.
     *
     * @return string|null The file name.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Set the displayable name of the file.
     *
     * @param string|null $name The file name.
     */
    public function as(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Specify HTTP headers for the file upload.
     *
     * @param \Closure((\Crustum\Ai\Enums\Lab|string)): ?array<string, string>|array<string, string> $headers The request headers.
     * @return $this
     */
    public function withHeaders(array|Closure $headers)
    {
        $this->headers = $headers instanceof Closure
            ? new SerializableClosure($headers)
            : $headers;

        return $this;
    }

    /**
     * Specify provider-specific options for the file upload.
     *
     * @param \Closure((\Crustum\Ai\Enums\Lab|string)): ?array<string, mixed>|array<string, mixed> $options The provider options.
     * @return $this
     */
    public function withProviderOptions(array|Closure $options)
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Get the provider-specific options for the file upload.
     *
     * @param \Crustum\Ai\Enums\Lab|string $provider The provider identifier.
     * @return array<string, mixed> The provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions instanceof SerializableClosure
            ? ($this->providerOptions)($provider) ?: []
            : $this->providerOptions;
    }

    /**
     * Get the HTTP headers for the file upload.
     *
     * @param \Crustum\Ai\Enums\Lab|string $provider The provider identifier.
     * @return array<string, string> The request headers.
     */
    public function headers(Lab|string $provider): array
    {
        return $this->headers instanceof SerializableClosure
            ? ($this->headers)($provider) ?: []
            : $this->headers;
    }

    /**
     * Get the file's MIME type.
     *
     * @return string|null The MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Set the file's MIME type.
     *
     * @param string $mimeType The MIME type.
     */
    public function withMimeType(string $mimeType): static
    {
        $this->mime = $mimeType;

        return $this;
    }
}
