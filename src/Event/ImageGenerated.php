<?php
declare(strict_types=1);

namespace Crustum\Ai\Event;

use Crustum\Ai\Prompts\ImagePrompt;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\ImageResponse;

/**
 * Dispatched after an image is generated.
 */
class ImageGenerated extends AiEvent
{
    /**
     * Constructor.
     *
     * @param string $invocationId Invocation identifier
     * @param \Crustum\Ai\Providers\Provider $provider Provider instance
     * @param string $model Model name
     * @param \Crustum\Ai\Prompts\ImagePrompt $prompt Image prompt
     * @param \Crustum\Ai\Responses\ImageResponse $response Image response
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
        public ImageResponse $response,
    ) {
        parent::__construct([
            'invocationId' => $invocationId,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'response' => $response,
        ]);
    }
}
