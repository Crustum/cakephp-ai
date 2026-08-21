<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\Groq\Trait;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\CreatesOpenAiCompatibleClientTrait;

/**
 * Creates HTTP clients for the Groq API.
 */
trait CreatesGroqClientTrait
{
    use CreatesOpenAiCompatibleClientTrait {
        baseUrl as protected openAiCompatibleBaseUrl;
    }

    /**
     * Get the base URL for the Groq API.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @return string
     */
    protected function baseUrl(Provider $provider): string
    {
        $url = $provider->additionalConfiguration()['url'] ?? 'https://api.groq.com/openai/v1';

        return rtrim((string)$url, '/');
    }
}
