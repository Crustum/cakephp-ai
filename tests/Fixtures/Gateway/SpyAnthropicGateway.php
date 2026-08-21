<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Gateway;

use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Anthropic\AnthropicGateway;
use Override;

class SpyAnthropicGateway extends AnthropicGateway
{
    public array $capturedTimeouts = [];

    #[Override]
    protected function client(Provider $provider, ?int $timeout = null): static
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}
