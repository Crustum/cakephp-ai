<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Video file.
 *
 * Abstract base class for video files (MP4, WebM, etc.).
 */
abstract class Video extends File
{
    /**
     * Create a new video from Base64 data.
     *
     * @param string $base64 Base64-encoded content
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Video
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Video
    {
        return new Base64Video($base64, $mimeType);
    }

    /**
     * Create a new video using the video at the given path.
     *
     * @param string $path Local file path
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\LocalVideo
     */
    public static function fromPath(string $path, ?string $mimeType = null): LocalVideo
    {
        return new LocalVideo($path, $mimeType);
    }

    /**
     * Create a new remote video using the video at the given URL.
     *
     * @param string $url Video URL
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\RemoteVideo
     */
    public static function fromUrl(string $url, ?string $mimeType = null): RemoteVideo
    {
        return new RemoteVideo($url, $mimeType);
    }

    /**
     * Create a new stored video using the video at the given path on the given filesystem.
     *
     * @param string $path Storage path
     * @param string|null $filesystem Named filesystem operator
     * @return \Crustum\Ai\Files\StoredVideo
     */
    public static function fromStorage(string $path, ?string $filesystem = null): StoredVideo
    {
        return new StoredVideo($path, $filesystem);
    }

    /**
     * Create a new Base64 video using the given file upload.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Video
     */
    public static function fromUpload(UploadedFileInterface $file, ?string $mimeType = null): Base64Video
    {
        $stream = $file->getStream();
        $stream->rewind();

        return (new Base64Video(
            base64_encode($stream->getContents()),
            $mimeType ?? $file->getClientMediaType(),
        ))->as($file->getClientFilename());
    }
}
