<?php
declare(strict_types=1);

namespace Crustum\Ai\Files;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Document file.
 *
 * Abstract base class for document files (PDF, DOCX, TXT, etc.).
 */
abstract class Document extends File
{
    /**
     * Get the raw bytes of the file.
     *
     * @return string
     */
    abstract public function content(): string;

    /**
     * Create a new document from a string.
     *
     * @param string $content Document content
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Document
     */
    public static function fromString(string $content, ?string $mimeType = null): Base64Document
    {
        return new Base64Document(base64_encode($content), $mimeType);
    }

    /**
     * Create a new document from Base64 data.
     *
     * @param string $base64 Base64-encoded content
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Document
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Document
    {
        return new Base64Document($base64, $mimeType);
    }

    /**
     * Create a new provider document using the document with the given ID.
     *
     * @param string $id Provider document ID
     * @return \Crustum\Ai\Files\ProviderDocument
     */
    public static function fromId(string $id): ProviderDocument
    {
        return new ProviderDocument($id);
    }

    /**
     * Create a new document using the document at the given path.
     *
     * @param string $path Local file path
     * @return \Crustum\Ai\Files\LocalDocument
     */
    public static function fromPath(string $path): LocalDocument
    {
        return new LocalDocument($path);
    }

    /**
     * Create a new remote document using the document at the given HTTP(S) URL.
     *
     * @param string $url Document URL
     * @return \Crustum\Ai\Files\RemoteDocument
     */
    public static function fromUrl(string $url): RemoteDocument
    {
        return new RemoteDocument($url);
    }

    /**
     * Create a new stored document using the document at the given path on the given filesystem.
     *
     * @param string $path Storage path
     * @param string|null $filesystem Named filesystem operator
     * @return \Crustum\Ai\Files\StoredDocument
     */
    public static function fromStorage(string $path, ?string $filesystem = null): StoredDocument
    {
        return new StoredDocument($path, $filesystem);
    }

    /**
     * Create a new S3 document using the document at the given S3 URI.
     *
     * @param string $url S3 URI
     * @param string|null $bucketOwner Optional bucket owner account ID
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\S3Document
     */
    public static function fromS3(string $url, ?string $bucketOwner = null, ?string $mimeType = null): S3Document
    {
        return new S3Document($url, $bucketOwner, $mimeType);
    }

    /**
     * Create a new Base64 document using the given file upload.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param string|null $mimeType MIME type
     * @return \Crustum\Ai\Files\Base64Document
     */
    public static function fromUpload(UploadedFileInterface $file, ?string $mimeType = null): Base64Document
    {
        $stream = $file->getStream();
        $stream->rewind();

        return (new Base64Document(
            base64_encode($stream->getContents()),
            $mimeType ?? $file->getClientMediaType(),
        ))->as($file->getClientFilename());
    }
}
