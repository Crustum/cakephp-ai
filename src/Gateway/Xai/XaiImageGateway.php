<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Xai;

use Cake\Collection\Collection;
use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\ImageGateway;
use Crustum\Ai\Contracts\Providers\ImageProvider;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Xai\Trait\CreatesXaiClientTrait;
use Crustum\Ai\Http\Contract\HttpResponseInterface;
use Crustum\Ai\Responses\Data\GeneratedImage;
use Crustum\Ai\Responses\Data\Meta;
use Crustum\Ai\Responses\Data\Usage;
use Crustum\Ai\Responses\ImageResponse;

/**
 * xAI image generation gateway.
 */
class XaiImageGateway implements ImageGateway
{
    use CreatesXaiClientTrait;
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
     * Generate an image.
     *
     * @param \Crustum\Ai\Contracts\Providers\ImageProvider $provider Image provider
     * @param string $model Model name
     * @param string $prompt Image prompt
     * @param array<int, \Crustum\Ai\Files\Image> $attachments Image attachments
     * @param string|null $size Image size
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
        $options = $provider->defaultImageOptions($size, $quality);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn(): HttpResponseInterface => $this->client($provider, $timeout ?? 120)
                ->post('images/generations', array_merge(array_filter([
                    'model' => $model,
                    'prompt' => $prompt,
                    'response_format' => 'b64_json',
                ]), $options)),
        );

        $data = $response->getJson() ?? [];

        return new ImageResponse(
            new Collection([
                new GeneratedImage($data['data'][0]['b64_json'], 'image/jpeg'),
            ]),
            new Usage(),
            new Meta($provider->name(), $model),
        );
    }
}
