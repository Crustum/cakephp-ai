<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter\Trait;

use Cake\Collection\CollectionInterface;
use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Audio;
use Crustum\Ai\Files\Base64Audio;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderDocument;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\StoredDocument;
use Crustum\Ai\Files\StoredImage;
use Crustum\Ai\Gateway\Trait\ResolvesDocumentFilenamesTrait;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to OpenRouter chat completion content parts.
 */
trait MapsAttachmentsTrait
{
    use ResolvesDocumentFilenamesTrait;

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
                $attachment instanceof Base64Document => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name() ?? $this->fallbackFilename($attachment->mime),
                        'file_data' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64,
                    ],
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name(),
                        'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(
                            (string)file_get_contents($attachment->path),
                        ),
                    ],
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'file',
                    'file' => array_filter([
                        'filename' => $attachment->name(),
                        'file_data' => $attachment->url,
                    ]),
                ],
                $attachment instanceof StoredDocument => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name(),
                        'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(
                            $attachment->content(),
                        ),
                    ],
                ],
                $attachment instanceof Base64Audio => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->mime ?? 'audio/mp3'),
                        'data' => $attachment->base64,
                    ],
                ],
                $attachment instanceof Audio && $attachment instanceof StorableFile => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->mimeType() ?? 'audio/mp3'),
                        'data' => base64_encode($attachment->content()),
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
                $attachment instanceof UploadedFile && $this->isAudio($attachment) => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->getClientMediaType()),
                        'data' => base64_encode(
                            $attachment->getStream()->getContents(),
                        ),
                    ],
                ],
                $attachment instanceof UploadedFile => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->getClientFilename(),
                        'file_data' => 'data:' . $attachment->getClientMediaType() . ';base64,' . base64_encode(
                            $attachment->getStream()->getContents(),
                        ),
                    ],
                ],
                $attachment instanceof ProviderDocument,
                $attachment instanceof ProviderImage => throw new InvalidArgumentException(
                    'Provider-stored attachments are not supported by OpenRouter; uploaded files may only be loaded into a sandbox container by the shell tool.',
                ),
                default => throw new InvalidArgumentException(
                    'Unsupported attachment type [' . $attachment::class . ']',
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

    /**
     * Determine if the given uploaded file is an audio file.
     *
     * @param \Laminas\Diactoros\UploadedFile $attachment Uploaded file
     * @return bool
     */
    protected function isAudio(UploadedFile $attachment): bool
    {
        return str_starts_with((string)$attachment->getClientMediaType(), 'audio/');
    }
}
