<?php
declare(strict_types=1);

use Crustum\Ai\Event\AgentFailedOver;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\TestSuite\AiFlow;

test('a rate limited provider is retried after its Retry-After delay before failing over', function (): void {
    aiConfigureGroqFailoverProviders();

    AiFlow::fakeProviderHttp([
        '*' => AiFlow::httpSequence([
            AiFlow::httpResponse(['error' => ['message' => 'Rate limited']], 429, ['Retry-After' => '1']),
            fakeGroqResponse('Hello after waiting.'),
        ]),
    ]);

    $response = (new AssistantAgent())->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Hello after waiting.')
        ->and($response->meta->provider)->toBe('primary');

    $this->assertAiEventNotDispatched(AgentFailedOver::class);
    $this->assertHttpSentCount(2);
});
