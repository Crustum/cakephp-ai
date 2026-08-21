<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures;

use Crustum\Broadcasting\Exception\BroadcastingException;
use Crustum\Broadcasting\TestSuite\TestBroadcaster;

class ThrowingBroadcaster extends TestBroadcaster
{
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        throw new BroadcastingException('Payload too large');
    }
}
