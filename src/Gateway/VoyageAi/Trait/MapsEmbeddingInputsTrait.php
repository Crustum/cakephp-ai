<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\VoyageAi\Trait;

use Crustum\Ai\Contracts\Files\StorableFile;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\Base64Video;
use Crustum\Ai\Files\Image as ImageFile;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Files\RemoteVideo;
use Crustum\Ai\Files\Video as VideoFile;
use InvalidArgumentException;

/**
 * Maps embeddings inputs to Voyage AI multimodal segments.
 */
trait MapsEmbeddingInputsTrait
{
    /**
     * Determine if the model or inputs require Voyage AI's multimodal endpoint.
     *
     * @param string $model Model name
     * @param array<int, mixed> $inputs Inputs to embed
     * @return bool
     */
    protected function usesMultimodalEmbeddingEndpoint(string $model, array $inputs): bool
    {
        if (in_array($model, ['voyage-multimodal-3.5', 'voyage-multimodal-3'], true)) {
            return true;
        }

        foreach ($inputs as $input) {
            if (!is_string($input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate Voyage AI's per-request media source constraint.
     *
     * @param array<int, mixed> $inputs Inputs to embed
     * @return void
     */
    protected function validateMultimodalEmbeddingInputSources(array $inputs): void
    {
        $source = null;

        foreach ($inputs as $input) {
            $inputSource = $this->multimodalEmbeddingInputSource($input);

            if ($inputSource === null) {
                continue;
            }

            $source ??= $inputSource;

            if ($source !== $inputSource) {
                throw new InvalidArgumentException(
                    'Voyage AI multimodal embeddings inputs must use either URL media or base64 media exclusively.',
                );
            }
        }
    }

    /**
     * Get the media source type used by a multimodal embeddings input.
     *
     * @param mixed $input Embeddings input
     * @return string|null
     */
    protected function multimodalEmbeddingInputSource(mixed $input): ?string
    {
        return match (true) {
            $input instanceof RemoteImage,
            $input instanceof RemoteVideo => 'url',
            $input instanceof ImageFile && $input instanceof StorableFile && !$input instanceof ProviderImage,
            $input instanceof VideoFile && $input instanceof StorableFile => 'base64',
            default => null,
        };
    }

    /**
     * Map an embeddings input to Voyage AI's multimodal input segment.
     *
     * @param mixed $input Input to embed
     * @return array<string, mixed>
     */
    protected function mapMultimodalEmbeddingInput(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'text' => $input,
            ];
        }

        if ($input instanceof RemoteImage) {
            return [
                'type' => 'image_url',
                'image_url' => $input->url,
            ];
        }

        if ($input instanceof RemoteVideo) {
            return [
                'type' => 'video_url',
                'video_url' => $input->url,
            ];
        }

        if ($input instanceof Base64Image) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64," . $input->base64,
            ];
        }

        if ($input instanceof Base64Video) {
            $mime = $input->mimeType() ?? 'video/mp4';

            return [
                'type' => 'video_base64',
                'video_base64' => "data:{$mime};base64," . $input->base64,
            ];
        }

        if ($input instanceof ImageFile && $input instanceof StorableFile && !$input instanceof ProviderImage) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64," . base64_encode($input->content()),
            ];
        }

        if ($input instanceof VideoFile && $input instanceof StorableFile) {
            $mime = $input->mimeType() ?? 'video/mp4';

            return [
                'type' => 'video_base64',
                'video_base64' => "data:{$mime};base64," . base64_encode($input->content()),
            ];
        }

        throw new InvalidArgumentException('Unsupported Voyage AI multimodal embeddings input type [' . get_debug_type($input) . ']');
    }
}
