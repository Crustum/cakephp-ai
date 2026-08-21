<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Mistral\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredImage;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to Mistral Chat Completions content parts.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Chat Completions content parts.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(fn(File|UploadedFile $attachment): array => match (true) {
            $attachment instanceof Base64Image => [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64],
            ],
            $attachment instanceof RemoteImage => [
                'type' => 'image_url',
                'image_url' => ['url' => $attachment->url],
            ],
            $attachment instanceof LocalImage => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                        (string)file_get_contents($attachment->path),
                    ),
                ],
            ],
            $attachment instanceof StoredImage => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                        $attachment->content(),
                    ),
                ],
            ],
            $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $attachment->getClientMediaType() . ';base64,' . base64_encode(
                        $attachment->getStream()->getContents(),
                    ),
                ],
            ],
            $attachment instanceof RemoteDocument => [
                'type' => 'document_url',
                'document_url' => $attachment->url,
                'document_name' => $attachment->name ?? basename($attachment->url),
            ],
            default => throw new InvalidArgumentException(
                'Mistral only supports image attachments and remote document URLs. Unsupported attachment type [' . $attachment::class . '].',
            ),
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
