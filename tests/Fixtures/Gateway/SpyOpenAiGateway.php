<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Gateway;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\OpenAi\OpenAiGateway;
use Override;

/**
 * OpenAI gateway spy for timeout assertions in tests.
 */
class SpyOpenAiGateway extends OpenAiGateway
{
    /**
     * Captured timeout values from client() calls.
     *
     * @var array<int, int|null>
     */
    public array $capturedTimeouts = [];

    /**
     * Configure an HTTP client and capture the timeout.
     *
     * @param \Crustum\Ai\Contracts\Providers\Provider $provider Provider instance
     * @param int|null $timeout Request timeout in seconds
     */
    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}
