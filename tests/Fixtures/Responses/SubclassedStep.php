<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Responses;

use Crustum\Ai\Responses\Data\Step;

class SubclassedStep extends Step
{
    private string $secret = 'default';

    public function rememberSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
