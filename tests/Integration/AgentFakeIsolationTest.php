<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\SecondaryAssistantAgent;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Test\Support\Skips\ApiKey;

beforeEach(function (): void {
    ApiKey::required('GROQ_API_KEY');

    $this->provider = 'groq';
    $this->model = 'openai/gpt-oss-20b';
});

test('faking one agent doesnt affect another agent', function (): void {
    AssistantAgent::fake(fn(): string => 'Fake response');

    $fakeResponse = (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $this->provider,
        model: $this->model,
    );

    $realResponse = (new SecondaryAssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $this->provider,
        model: $this->model,
    );

    expect($fakeResponse->text)->toEqual('Fake response')
        ->and(IntegrationPrompts::matches('knowledge', $realResponse->text))->toBeTrue();
});
