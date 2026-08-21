<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Files\StoredImage;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to xAI content parts.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to xAI content parts.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(fn(File|UploadedFile $attachment): array => match (true) {
            $attachment instanceof Base64Image => [
                'type' => 'input_image',
                'image_url' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64,
            ],
            $attachment instanceof RemoteImage => [
                'type' => 'input_image',
                'image_url' => $attachment->url,
            ],
            $attachment instanceof LocalImage => [
                'type' => 'input_image',
                'image_url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                    (string)file_get_contents($attachment->path),
                ),
            ],
            $attachment instanceof StoredImage => [
                'type' => 'input_image',
                'image_url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                    $attachment->content(),
                ),
            ],
            $attachment instanceof ProviderDocument => array_filter([
                'type' => 'input_file',
                'file_id' => $attachment->id,
                'filename' => $attachment->name(),
            ]),
            $attachment instanceof Base64Document => array_filter([
                'type' => 'input_file',
                'file_data' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64,
                'filename' => $attachment->name(),
            ]),
            $attachment instanceof LocalDocument => array_filter([
                'type' => 'input_file',
                'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(
                    (string)file_get_contents($attachment->path),
                ),
                'filename' => $attachment->name(),
            ]),
            $attachment instanceof RemoteDocument => array_filter([
                'type' => 'input_file',
                'file_url' => $attachment->url,
                'filename' => $attachment->name(),
            ]),
            $attachment instanceof StoredDocument => array_filter([
                'type' => 'input_file',
                'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(
                    $attachment->content(),
                ),
                'filename' => $attachment->name(),
            ]),
            $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                'type' => 'input_image',
                'image_url' => 'data:' . $attachment->getClientMediaType() . ';base64,' . base64_encode(
                    $attachment->getStream()->getContents(),
                ),
            ],
            $attachment instanceof UploadedFile => [
                'type' => 'input_file',
                'file_data' => 'data:' . $attachment->getClientMediaType() . ';base64,' . base64_encode(
                    $attachment->getStream()->getContents(),
                ),
                'filename' => $attachment->getClientFilename(),
            ],
            default => throw new InvalidArgumentException('Unsupported attachment type [' . $attachment::class . ']'),
        })->toList();
    }

    /**
     * Determine if the given uploaded file is an image.
     *
     * @param \Laminas\Diactoros\UploadedFile $attachment Uploaded file
     * @return bool
     */
    protected function isImage(UploadedFile $attachment): bool
    {
        return in_array($attachment->getClientMediaType(), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], true);
    }
}
