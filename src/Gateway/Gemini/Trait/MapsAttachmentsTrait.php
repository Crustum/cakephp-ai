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
 * Maps file attachments to Gemini content blocks.
 */
trait MapsAttachmentsTrait
{
    /**
     * Map the given attachments to Gemini content blocks.
     *
     * @param \Cake\Collection\CollectionInterface<int, mixed> $attachments Attachments
     * @return array<int, array<string, mixed>>
     */
    protected function mapAttachments(CollectionInterface $attachments): array
    {
        return $attachments->map(fn(mixed $attachment): array => $this->mapAttachment($attachment))->toList();
    }

    /**
     * Map an attachment to a Gemini content block.
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
                'type' => 'image',
                'uri' => $attachment->id,
            ],
            $attachment instanceof Base64Image => [
                'type' => 'image',
                'mime_type' => $attachment->mime,
                'data' => $attachment->base64,
            ],
            $attachment instanceof RemoteImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof LocalImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode((string)file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof ProviderDocument => [
                'type' => 'document',
                'uri' => $attachment->id,
            ],
            $attachment instanceof Base64Document => [
                'type' => 'document',
                'mime_type' => $attachment->mime,
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode((string)file_get_contents($attachment->path)),
            ],
            $attachment instanceof RemoteDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof StoredDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof Base64Audio => [
                'type' => 'audio',
                'mime_type' => $attachment->mime ?? 'audio/mp3',
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode((string)file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof RemoteAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof Base64Video => [
                'type' => 'video',
                'mime_type' => $attachment->mime ?? 'video/mp4',
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalVideo => [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode((string)file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredVideo => [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof RemoteVideo => $this->isYouTubeUrl($attachment->url) ? array_filter([
                'type' => 'video',
                'mime_type' => $attachment->mime,
                'uri' => $attachment->url,
            ]) : [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof UploadedFile => [
                'type' => $this->contentTypeFor($attachment->getClientMediaType()),
                'mime_type' => $attachment->getClientMediaType(),
                'data' => base64_encode($attachment->getStream()->getContents()),
            ],
            default => throw new InvalidArgumentException('Unsupported attachment type [' . get_debug_type($attachment) . ']'),
        };
    }

    /**
     * Resolve the Gemini content block type for the given MIME type.
     *
     * @param string|null $mime MIME type
     * @return string
     */
    protected function contentTypeFor(?string $mime): string
    {
        return match (strtok((string)$mime, '/')) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            default => 'document',
        };
    }

    /**
     * Determine if the given URL is a YouTube URL, which Gemini accepts as a file URI.
     *
     * @param string $url URL to inspect
     * @return bool
     */
    protected function isYouTubeUrl(string $url): bool
    {
        return in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), [
            'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be',
        ], true);
    }
}
