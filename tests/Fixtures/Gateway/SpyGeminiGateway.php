<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Gateway;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Gemini\GeminiGateway;
use Override;

class SpyGeminiGateway extends GeminiGateway
{
    public array $capturedTimeouts = [];

    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}
