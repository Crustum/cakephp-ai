<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Anthropic\Trait;

use Cake\Collection\CollectionInterface;
use Closure;
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
use Crustum\Ai\Utility\Value;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Maps file attachments to Anthropic content blocks.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Anthropic content blocks.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(function (File|UploadedFile $attachment): array {
            $mapped = match (true) {
                $attachment instanceof ProviderImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'file',
                        'file_id' => $attachment->id,
                    ],
                ],
                $attachment instanceof Base64Image => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mime,
                        'data' => $attachment->base64,
                    ],
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'url',
                        'url' => $attachment->url,
                    ],
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode((string)file_get_contents($attachment->path)),
                    ],
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode($attachment->content()),
                    ],
                ],
                $attachment instanceof ProviderDocument => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'file',
                        'file_id' => $attachment->id,
                    ],
                ],
                $attachment instanceof Base64Document => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->mime,
                        fn(): string => base64_decode($attachment->base64),
                        fn(): string => $attachment->base64,
                    ),
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->mimeType(),
                        fn(): string => (string)file_get_contents($attachment->path),
                        fn(): string => base64_encode((string)file_get_contents($attachment->path)),
                    ),
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'document',
                    'source' => $this->remoteDocumentSource($attachment),
                ],
                $attachment instanceof StoredDocument => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->mimeType(),
                        fn(): string => $attachment->content(),
                        fn(): string => base64_encode($attachment->content()),
                    ),
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->getClientMediaType(),
                        'data' => base64_encode($attachment->getStream()->getContents()),
                    ],
                ],
                $attachment instanceof UploadedFile => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->getClientMediaType(),
                        fn(): string => $attachment->getStream()->getContents(),
                        fn(): string => base64_encode($attachment->getStream()->getContents()),
                    ),
                ],
                default => throw new InvalidArgumentException('Unsupported attachment type [' . $attachment::class . ']'),
            };

            if ($mapped['type'] === 'document' && $attachment instanceof File && Value::filled($attachment->name())) {
                $mapped['title'] = $attachment->name();
            }

            return $mapped;
        })->toList();
    }

    /**
     * Build the Anthropic document `source` block for the given mime type.
     *
     * @param string|null $mimeType MIME type
     * @param \Closure(): string $rawResolver Raw content resolver
     * @param \Closure(): string $base64Resolver Base64 content resolver
     * @return array<string, string>
     */
    protected function documentSource(?string $mimeType, Closure $rawResolver, Closure $base64Resolver): array
    {
        if ($this->normalizeMimeType($mimeType) === 'application/pdf') {
            return [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $base64Resolver(),
            ];
        }

        $raw = (string)$rawResolver();

        if (str_starts_with($raw, '%PDF-')) {
            return [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => base64_encode($raw),
            ];
        }

        if (!mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw new InvalidArgumentException('Anthropic only accepts PDF or plain text documents; [' . ($mimeType ?? 'unknown') . '] must be converted first.');
        }

        return [
            'type' => 'text',
            'media_type' => 'text/plain',
            'data' => $raw,
        ];
    }

    /**
     * Build the Anthropic document `source` block for the given remote document.
     *
     * @param \Crustum\Ai\Files\RemoteDocument $document Remote document
     * @return array<string, string>
     */
    protected function remoteDocumentSource(RemoteDocument $document): array
    {
        $mimeType = $this->normalizeMimeType($document->declaredMimeType());

        // A `url` source is PDF-only, so anything else has to be fetched and inlined.
        $isPdf = $mimeType === null
            ? in_array(strtolower(pathinfo((string)parse_url($document->url, PHP_URL_PATH), PATHINFO_EXTENSION)), ['', 'pdf'], true)
            : $mimeType === 'application/pdf';

        if ($isPdf) {
            return [
                'type' => 'url',
                'url' => $document->url,
            ];
        }

        return $this->documentSource(
            $document->mimeType(),
            fn(): string => $document->content(),
            fn(): string => base64_encode($document->content()),
        );
    }

    /**
     * Strip any parameters from the given mime type.
     *
     * @param string|null $mimeType MIME type
     * @return string|null
     */
    protected function normalizeMimeType(?string $mimeType): ?string
    {
        return Value::blank($mimeType) ? null : strtolower(trim(explode(';', $mimeType, 2)[0]));
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
