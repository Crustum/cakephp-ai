<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

/**
 * Image
 *
 * Abstract base class for image attachments.
 * Provides factory methods for creating images from various sources.
 */
abstract class Image extends File
{
    /**
     * Create a new image from Base64 data.
     *
     * @param string $base64 The Base64-encoded image data.
     * @param string|null $mimeType The MIME type of the image.
     * @return \Crustum\Ai\Files\Base64Image The Base64 image instance.
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Image
    {
        return new Base64Image($base64, $mimeType);
    }

    /**
     * Create a new provider image using the image with the given ID.
     *
     * @param string $id The provider-specific image ID.
     * @return \Crustum\Ai\Files\ProviderImage The provider image instance.
     */
    public static function fromId(string $id): ProviderImage
    {
        return new ProviderImage($id);
    }

    /**
     * Create a new image using the image at the given path.
     *
     * @param string $path The local file path.
     * @param string|null $mimeType The MIME type of the image.
     * @return \Crustum\Ai\Files\LocalImage The local image instance.
     */
    public static function fromPath(string $path, ?string $mimeType = null): LocalImage
    {
        return new LocalImage($path, $mimeType);
    }

    /**
     * Create a new remote image using the image at the given URL.
     *
     * @param string $url The image URL.
     * @return \Crustum\Ai\Files\RemoteImage The remote image instance.
     */
    public static function fromUrl(string $url): RemoteImage
    {
        return new RemoteImage($url);
    }

    /**
     * Create a new stored image using the image at the given path on the given filesystem.
     *
     * @param string $path The storage path.
     * @param string|null $filesystem Named filesystem operator.
     * @return \Crustum\Ai\Files\StoredImage The stored image instance.
     */
    public static function fromStorage(string $path, ?string $filesystem = null): StoredImage
    {
        return new StoredImage($path, $filesystem);
    }
}
