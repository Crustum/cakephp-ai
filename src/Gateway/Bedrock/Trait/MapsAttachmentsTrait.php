<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Document;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\S3Document;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Files\StoredImage;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to Bedrock Converse content blocks.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Bedrock content blocks.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(fn(File|UploadedFile $attachment): array => match (true) {
            $attachment instanceof Base64Document,
            $attachment instanceof LocalDocument,
            $attachment instanceof S3Document,
            $attachment instanceof StoredDocument => $this->buildDocumentBlock($attachment),
            $attachment instanceof Base64Image => $this->buildImageBlock($attachment, $attachment->content()),
            $attachment instanceof LocalImage => $this->buildImageBlock($attachment, (string)file_get_contents($attachment->path)),
            $attachment instanceof StoredImage => $this->buildImageBlock($attachment, $attachment->content()),
            $attachment instanceof RemoteDocument,
            $attachment instanceof RemoteImage => throw new InvalidArgumentException(
                'Remote attachments are not supported by Bedrock; download the file and pass it as a Base64, Local, or Stored attachment.',
            ),
            $attachment instanceof ProviderDocument,
            $attachment instanceof ProviderImage => throw new InvalidArgumentException(
                'Provider-stored attachments are not supported by Bedrock.',
            ),
            default => throw new InvalidArgumentException('Unsupported attachment type [' . $attachment::class . '].'),
        })->toList();
    }

    /**
     * Build a Bedrock document content block.
     *
     * @param \Crustum\Ai\Files\Document $document Document attachment
     * @return array<string, mixed>
     */
    protected function buildDocumentBlock(Document $document): array
    {
        $source = match (true) {
            $document instanceof S3Document => [
                's3Location' => array_filter([
                    'uri' => $document->url,
                    'bucketOwner' => $document->bucketOwner,
                ]),
            ],
            default => ['bytes' => $document->content()],
        };

        return [
            'document' => array_filter([
                'format' => $this->getDocumentFormat($document),
                'name' => $this->getDocumentName($document),
                'source' => $source,
            ]),
        ];
    }

    /**
     * Build a Bedrock image content block.
     *
     * @param \Crustum\Ai\Files\Image $image Image attachment
     * @param string $bytes Image bytes
     * @return array<string, mixed>
     */
    protected function buildImageBlock(Image $image, string $bytes): array
    {
        return [
            'image' => [
                'format' => $this->getImageFormat($image),
                'source' => [
                    'bytes' => $bytes,
                ],
            ],
        ];
    }

    /**
     * Map a Document's MIME type to a Bedrock document format.
     *
     * @param \Crustum\Ai\Files\Document $document Document attachment
     * @return string|null
     */
    protected function getDocumentFormat(Document $document): ?string
    {
        $mime = strtolower(trim((string)strtok($document->mimeType() ?? '', ';')));

        if ($mime === '' || $mime === '0') {
            return null;
        }

        return match ($mime) {
            'application/pdf' => 'pdf',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/markdown', 'text/x-markdown' => 'md',
            'text/plain' => 'txt',
            default => null,
        };
    }

    /**
     * Build a unique, Bedrock-compliant document name.
     *
     * @param \Crustum\Ai\Files\Document $document Document attachment
     * @return string
     */
    protected function getDocumentName(Document $document): string
    {
        $name = $document->name() ?? 'document';
        $name = pathinfo($name, PATHINFO_FILENAME) ?: $name;
        $name = preg_replace('/[^A-Za-z0-9\-\(\)\[\] ]+/', '-', $name);

        return trim((string)preg_replace('/\s+/', ' ', (string)$name)) ?: 'document';
    }

    /**
     * Map an Image's MIME type to a Bedrock image format.
     *
     * @param \Crustum\Ai\Files\Image $image Image attachment
     * @return string
     * @throws \InvalidArgumentException if the MIME type cannot be determined or is unsupported.
     */
    protected function getImageFormat(Image $image): string
    {
        $mime = $image->mimeType();

        if (!$mime) {
            throw new InvalidArgumentException('Unable to determine MIME type for image [' . $image->name() . '].');
        }

        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Unsupported image MIME type [' . $mime . '].'),
        };
    }
}
