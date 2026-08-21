<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Providers\Provider;
use Crustum\Ai\Responses\AgentResponse;

test('string default works', function (): void {
    Configure::write('Ai.defaultProvider', 'openai');

    expect(Ai::manager()->getDefaultProvider())->toBe('openai');
});

test('array default via Ai facade throws helpful error', function (): void {
    Configure::write('Ai.defaultProvider', ['text' => 'anthropic']);

    expect(fn(): string => Ai::manager()->getDefaultProvider())
        ->toThrow(InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
});

test('array default via agent prompt throws helpful error', function (): void {
    Configure::write('Ai.defaultProvider', ['text' => 'anthropic']);

    expect(fn(): AgentResponse => agent(instructions: 'test')->prompt('hello'))
        ->toThrow(InvalidArgumentException::class, 'must be a string provider name or a Lab enum, not an array');
});

test('failover provider list still works', function (): void {
    expect(Provider::formatProviderAndModelList(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']))
        ->toBe(['anthropic' => 'claude-3-5-sonnet', 'openai' => 'gpt-4']);
});
