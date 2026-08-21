<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsAgent;
use Crustum\Ai\Test\Fixtures\Agents\ProviderOptionsWithToolsAgent;

test('provider options are included in anthropic request body', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    aiAssertHttpSent(function ($request): bool {
        $body = $request->data();

        return isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && $body['thinking']['budget_tokens'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'anthropic',
    );

    aiAssertHttpSent(fn($request): bool => ! isset($request->data()['thinking']));
});

test('provider options are persisted in tool call follow up requests', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ProviderOptionsWithToolsAgent())->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $firstBody = $recorded[0][0]->data();
    expect($firstBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);

    $secondBody = $recorded[1][0]->data();
    expect($secondBody)->toHaveKey('thinking')
        ->and($secondBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);
});
