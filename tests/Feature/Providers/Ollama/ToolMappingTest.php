<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.ollama.key', '');
});

test('tool with parameters includes correct schema', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return ($function['parameters']['type'] ?? null) === 'object'
            && array_key_exists('min', $function['parameters']['properties'] ?? [])
            && array_key_exists('max', $function['parameters']['properties'] ?? [])
            && in_array('min', $function['parameters']['required'] ?? [], true)
            && in_array('max', $function['parameters']['required'] ?? [], true);
    });
});

test('tool with empty schema includes parameters', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a number', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return array_key_exists('parameters', $function)
            && ($function['parameters']['type'] ?? null) === 'object';
    });
});

test('tool with a name() method emits the declared name', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('function.name')->toList();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool definition includes name and description', function (): void {
    aiHttpFake([
        '*' => $this->fakeTextResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a number', provider: 'ollama');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && ($item['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return !empty($function['name'])
            && !empty($function['description']);
    });
});
