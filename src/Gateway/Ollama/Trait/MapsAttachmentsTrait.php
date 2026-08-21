<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Ollama\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredImage;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to Ollama base64 image strings.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Ollama base64 image strings.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, string>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(function ($attachment): string {
            if (!$attachment instanceof File && !$attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type [' . $attachment::class . ']',
                );
            }

            return match (true) {
                $attachment instanceof Base64Image => $attachment->base64,
                $attachment instanceof LocalImage => base64_encode((string)file_get_contents($attachment->path)),
                $attachment instanceof StoredImage => base64_encode($attachment->content()),
                $attachment instanceof UploadedFile && $this->isImage($attachment) => base64_encode(
                    $attachment->getStream()->getContents(),
                ),
                $attachment instanceof RemoteImage => throw new InvalidArgumentException(
                    'Ollama does not support remote image URLs. Use a local or base64 image instead.',
                ),
                default => throw new InvalidArgumentException(
                    'Ollama does not support document attachments. Only image attachments are supported.',
                ),
            };
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
