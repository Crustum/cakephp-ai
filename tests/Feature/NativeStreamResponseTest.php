<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('a streamed response is sent with headers that stop a proxy buffering it', function (): void {
    AssistantAgent::fake(['Hello world']);

    $response = (new AssistantAgent())->stream('Hi')->toResponse();

    expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream')
        ->and($response->getHeaderLine('Cache-Control'))->toContain('no-transform')
        ->and($response->getHeaderLine('Cache-Control'))->toContain('no-cache')
        ->and($response->getHeaderLine('X-Accel-Buffering'))->toBe('no');
});
