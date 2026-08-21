<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Gemini\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Files\Base64Audio;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Base64Video;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalAudio;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\LocalVideo;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteAudio;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;
use Crustum\Ai\Files\StoredAudio;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Files\StoredImage;
use Crustum\Ai\Files\StoredVideo;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to Gemini content parts.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Gemini content parts.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(fn(mixed $attachment): array => $this->mapAttachment($attachment))->toList();
    }

    /**
     * Map an attachment to a Gemini content part.
     *
     * @param mixed $attachment Attachment
     * @return array<string, mixed>
     */
    protected function mapAttachment(mixed $attachment): array
    {
        if (!$attachment instanceof File && !$attachment instanceof UploadedFile) {
            throw new InvalidArgumentException(
                'Unsupported attachment type [' . get_debug_type($attachment) . ']',
            );
        }

        return match (true) {
            $attachment instanceof ProviderImage => [
                'fileData' => [
                    'fileUri' => $attachment->id,
                ],
            ],
            $attachment instanceof Base64Image => [
                'inlineData' => [
                    'mimeType' => $attachment->mime,
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof RemoteImage => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof LocalImage => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'image/png',
                    'data' => base64_encode((string)file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredImage => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'image/png',
                    'data' => base64_encode($attachment->content()),
                ],
            ],
            $attachment instanceof ProviderDocument => [
                'fileData' => [
                    'fileUri' => $attachment->id,
                ],
            ],
            $attachment instanceof Base64Document => [
                'inlineData' => [
                    'mimeType' => $attachment->mime,
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalDocument => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'application/octet-stream',
                    'data' => base64_encode((string)file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof RemoteDocument => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof StoredDocument => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'application/octet-stream',
                    'data' => base64_encode($attachment->content()),
                ],
            ],
            $attachment instanceof Base64Audio => [
                'inlineData' => [
                    'mimeType' => $attachment->mime ?? 'audio/mp3',
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalAudio => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'audio/mp3',
                    'data' => base64_encode((string)file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredAudio => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'audio/mp3',
                    'data' => base64_encode($attachment->content()),
                ],
            ],
            $attachment instanceof RemoteAudio => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof Base64Video => [
                'inlineData' => [
                    'mimeType' => $attachment->mime ?? 'video/mp4',
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalVideo => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'video/mp4',
                    'data' => base64_encode((string)file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredVideo => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'video/mp4',
                    'data' => base64_encode($attachment->content()),
                ],
            ],
            $attachment instanceof RemoteVideo => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof UploadedFile => [
                'inlineData' => [
                    'mimeType' => $attachment->getClientMediaType(),
                    'data' => base64_encode($attachment->getStream()->getContents()),
                ],
            ],
            default => throw new InvalidArgumentException('Unsupported attachment type [' . get_debug_type($attachment) . ']'),
        };
    }
}
