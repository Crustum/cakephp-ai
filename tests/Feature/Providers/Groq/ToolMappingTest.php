<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.groq', [

        ...(array)Configure::read('Ai.providers.groq'),
        'key' => 'test-key',
    ]);
});

test('tool with parameters includes correct schema', function (): void {
    aiHttpFake([
        '*' => fakeGroqResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return $function['parameters']['type'] === 'object'
            && array_key_exists('min', $function['parameters']['properties'])
            && array_key_exists('max', $function['parameters']['properties'])
            && in_array('min', $function['parameters']['required'])
            && in_array('max', $function['parameters']['required'])
            && $function['parameters']['additionalProperties'] === false;
    });
});

test('tool with empty schema includes parameters', function (): void {
    aiHttpFake([
        '*' => fakeGroqResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a random number', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return array_key_exists('parameters', $function)
            && $function['parameters']['type'] === 'object'
            && $function['parameters']['properties'] === []
            && $function['parameters']['required'] === []
            && $function['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function (): void {
    aiHttpFake([
        '*' => fakeGroqResponse('ok'),
    ]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('function.name')->toList();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    aiHttpFake([
        '*' => fakeGroqResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'groq');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($m): bool => ($m['type'] ?? null) === 'function')->first();
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});
