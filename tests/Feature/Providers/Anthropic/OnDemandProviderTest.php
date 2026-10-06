<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;
use Crustum\Ai\Job\InvokeAgentJob;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\OnDemandProviderAgent;

test('prompts use an on-demand provider passed on its own', function (): void {
    aiHttpFake([
        'tenant.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: Ai::manager()->build([
        'driver' => 'anthropic',
        'key' => 'tenant-key',
        'url' => 'https://tenant.example.com/v1',
    ]), model: 'claude-opus-5-5');

    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://tenant.example.com/v1/messages'
        && $request->header('x-api-key') === ['tenant-key']
        && $request['model'] === 'claude-opus-5-5');
});

test('prompts fail over between on-demand providers', function (): void {
    aiHttpFake([
        'primary.example.com/*' => aiHttpResponse(['error' => ['message' => 'Rate limited']], 429),
        'backup.example.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent())->prompt('Hi', provider: [
        Ai::manager()->build(['driver' => 'anthropic', 'key' => 'primary-key', 'url' => 'https://primary.example.com/v1']),
        Ai::manager()->build(['driver' => 'anthropic', 'key' => 'backup-key', 'url' => 'https://backup.example.com/v1']),
    ]);

    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://primary.example.com/v1/messages'
        && $request->header('x-api-key') === ['primary-key']);
    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://backup.example.com/v1/messages'
        && $request->header('x-api-key') === ['backup-key']);
});

test('an agent provider method rebuilds its on-demand provider on the queue worker', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $payload = InvokeAgentJob::payload(new OnDemandProviderAgent('tenant-key'), 'Hi');

    Ai::manager()->flushState();
    Ai::manager()->clearInstances();

    (new InvokeAgentJob())->run($payload);

    aiAssertHttpSent(fn($request): bool => $request->header('x-api-key') === ['tenant-key']);
});

test('a queued prompt fails clearly when its on-demand provider was built at the call site', function (): void {
    $payload = InvokeAgentJob::payload(new AssistantAgent(), 'Hi', provider: Ai::manager()->build([
        'driver' => 'anthropic',
        'key' => 'tenant-key',
    ]));

    $provider = InvokeAgentJob::unpack($payload['provider']);

    Ai::manager()->flushState();
    Ai::manager()->clearInstances();

    (new AssistantAgent())->prompt('Hi', provider: $provider);
})->throws(InvalidArgumentException::class, 'was not built in this process');

test('a queued prompt rebuilds an on-demand provider built at the call site', function (): void {
    aiHttpFake([
        'tenant.example.com/*' => $this->fakeTextResponse(),
    ]);

    $payload = InvokeAgentJob::payload(new AssistantAgent(), 'Hi', provider: [Ai::manager()->build([
        'driver' => 'anthropic',
        'key' => 'tenant-key',
        'url' => 'https://tenant.example.com/v1',
    ])]);

    Ai::manager()->flushState();
    Ai::manager()->clearInstances();

    (new InvokeAgentJob())->run($payload);

    aiAssertHttpSent(fn($request): bool => $request->url() === 'https://tenant.example.com/v1/messages'
        && $request->header('x-api-key') === ['tenant-key']);
});

test('a serialized on-demand provider keeps its key out of the payload', function (): void {
    expect(serialize(Ai::manager()->build(['driver' => 'anthropic', 'key' => 'tenant-key'])))->not->toContain('tenant-key');
});

test('a serialized configured provider travels by name', function (): void {
    Configure::write('Ai.providers.anthropic.key', 'configured-key');

    $provider = unserialize(serialize(Ai::manager()->textProvider('anthropic')));

    expect(serialize(Ai::manager()->textProvider('anthropic')))->not->toContain('configured-key')
        ->and($provider->name())->toBe('anthropic')
        ->and($provider->providerCredentials())->toBe(['key' => 'configured-key']);
});
