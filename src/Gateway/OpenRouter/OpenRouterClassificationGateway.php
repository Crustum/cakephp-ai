<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenRouter;

use Crustum\Ai\Contracts\Gateway\ClassificationGateway;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\OpenRouter\Trait\CreatesOpenRouterClientTrait;
use Crustum\Ai\Gateway\Trait\AnswersQuestionsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;

/**
 * OpenRouter Decisions API classification gateway.
 */
class OpenRouterClassificationGateway implements ClassificationGateway
{
    use AnswersQuestionsTrait;
    use CreatesOpenRouterClientTrait {
        baseUrl as openRouterBaseUrl;
    }
    use HandlesFailoverErrorsTrait;

    /**
     * Get the path of the endpoint that answers questions.
     *
     * @return string
     */
    protected function classificationEndpoint(): string
    {
        return '/alpha/decisions';
    }

    /**
     * Get the base URL for the Decisions API, which is served outside of the versioned API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        $baseUrl = $this->openRouterBaseUrl($provider);

        return str_ends_with($baseUrl, '/v1') ? substr($baseUrl, 0, -strlen('/v1')) : $baseUrl;
    }
}
