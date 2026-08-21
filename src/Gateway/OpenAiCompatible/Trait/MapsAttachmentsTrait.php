<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredImage;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to OpenAI-compatible Chat Completions content parts.
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
        return $attachments->map(function ($attachment): array {
            if (!$attachment instanceof File && !$attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type [' . $attachment::class . ']',
                );
            }

            return match (true) {
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
                default => throw new InvalidArgumentException(
                    'This openai-compatible provider does not support document attachments. Only image attachments are supported.',
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
