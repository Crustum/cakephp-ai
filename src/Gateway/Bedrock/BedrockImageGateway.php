<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Bedrock;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Gateway\Bedrock\Trait\CreatesBedrockClientTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\ImageResponse;
use Throwable;

/**
 * AWS Bedrock image generation gateway.
 */
class BedrockImageGateway implements ImageGateway
{
    use CreatesBedrockClientTrait;
    use HandlesFailoverErrorsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Generate an image using AWS Bedrock.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param '3:2'|'2:3'|'1:1'|null $size Image size
     * @param 'low'|'medium'|'high'|null $quality Image quality
     * @param int|null $timeout Timeout in seconds
     * @return \Crustum\Ai\Responses\ImageResponse
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $client = $this->createBedrockClient($provider, $timeout);
        $options = $provider->defaultImageOptions($size, $quality);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn() => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode($this->prepareImageRequestBody($model, $prompt, $size, $options)),
                ]),
            );
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        $result = json_decode((string)$response->get('body')->getContents(), true);

        return new ImageResponse(
            $this->parseImageResponse($model, $result),
            new Usage(),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Prepare the request body for the given model family.
     *
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param '3:2'|'2:3'|'1:1'|null $size Image size
     * @param array{quality: string, size: string} $options Image options
     * @return array<string, mixed>
     */
    protected function prepareImageRequestBody(string $model, string $prompt, ?string $size, array $options): array
    {
        [$width, $height] = $this->parseSize($size);
        $quality = $options['quality'];

        return match (true) {
            str_starts_with($model, 'stability.') => array_filter([
                'prompt' => $prompt,
                'aspect_ratio' => in_array($size, ['1:1', '2:3', '3:2'], true) ? $size : null,
                'output_format' => 'png',
            ]),
            str_starts_with($model, 'amazon.titan-image') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'height' => $height,
                    'width' => $width,
                    'cfgScale' => 7.0,
                ],
            ],
            str_starts_with($model, 'amazon.nova-canvas') => [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => ['text' => $prompt],
                'imageGenerationConfig' => [
                    'numberOfImages' => 1,
                    'quality' => $quality,
                    'width' => $width,
                    'height' => $height,
                ],
            ],
            default => ['prompt' => $prompt],
        };
    }

    /**
     * Parse the image response payload into GeneratedImage instances.
     *
     * @param string $model Model name
     * @param array<string, mixed> $result Response payload
     * @return \Cake\Collection\Collection<int, \Crustum\Ai\Responses\Data\GeneratedImage>
     */
    protected function parseImageResponse(string $model, array $result): Collection
    {
        if (
            str_starts_with($model, 'stability.')
            || str_starts_with($model, 'amazon.titan-image')
            || str_starts_with($model, 'amazon.nova-canvas')
        ) {
            return (new Collection($result['images'] ?? []))
                ->map(fn($image): GeneratedImage => new GeneratedImage($image ?? '', 'image/png'));
        }

        return new Collection([]);
    }

    /**
     * Parse an aspect-ratio size into explicit [width, height] dimensions.
     *
     * @param '3:2'|'2:3'|'1:1'|null $size Image size
     * @return array{0: int, 1: int}
     */
    protected function parseSize(?string $size): array
    {
        return match ($size) {
            '2:3' => [768, 1152],
            '3:2' => [1152, 768],
            default => [1024, 1024],
        };
    }
}
