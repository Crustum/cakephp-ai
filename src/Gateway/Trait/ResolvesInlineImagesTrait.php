<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Trait;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\File;
use Crustum\Ai\Files\Image;
use finfo;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;

/**
 * Resolves classification attachments to images with inline content.
 */
trait ResolvesInlineImagesTrait
{
    /**
     * Resolve an attachment to an image with its content and MIME type.
     *
     * @param \Crustum\Ai\Files\File|\Laminas\Diactoros\UploadedFile $attachment Attachment to resolve
     * @param string $endpoint Endpoint name used in exception messages
     * @param array<string, string> $formats Accepted MIME types mapped to their display names
     * @return array{0: \Crustum\Ai\Files\Image&\Crustum\Ai\Contracts\Files\StorableFile, 1: string, 2: string}
     * @throws \InvalidArgumentException if the attachment is not an image with inline content in one of the formats.
     */
    protected function resolveInlineImage(File|UploadedFile $attachment, string $endpoint, array $formats): array
    {
        $image = $attachment instanceof UploadedFile
            ? new Base64Image(
                base64_encode($attachment->getStream()->getContents()),
                $attachment->getClientMediaType() ?: null,
            )
            : $attachment;

        if (!$image instanceof Image || !$image instanceof StorableFile) {
            throw new InvalidArgumentException("{$endpoint} only accept images with inline content; [" . get_debug_type($image) . '] given.');
        }

        $content = $image->content();

        $mime = $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (!array_key_exists($mime, $formats)) {
            throw new InvalidArgumentException("{$endpoint} only accept " . $this->joinFormats($formats) . " images; [{$mime}] given.");
        }

        return [$image, $content, $mime];
    }

    /**
     * Join format display names for an exception message.
     *
     * @param array<string, string> $formats Accepted MIME types mapped to their display names
     * @return string
     */
    protected function joinFormats(array $formats): string
    {
        $names = array_values($formats);

        if (count($names) > 2) {
            $last = array_pop($names);

            return implode(', ', $names) . ', or ' . $last;
        }

        return implode(' or ', $names);
    }
}
