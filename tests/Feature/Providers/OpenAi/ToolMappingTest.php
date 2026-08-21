<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\NonStrictTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('tool with parameters includes strict compliant schema', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();

        return $tool['strict'] === true
            && $tool['parameters']['type'] === 'object'
            && array_key_exists('min', $tool['parameters']['properties'])
            && array_key_exists('max', $tool['parameters']['properties'])
            && in_array('min', $tool['parameters']['required'])
            && in_array('max', $tool['parameters']['required'])
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('name')->toList();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool without a name() method falls back to class basename for openai', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Hi', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('name')->toList();

        return in_array('FixedNumberGenerator', $names, true);
    });
});

test('tool without Strict attribute sends strict false and honors developer-declared required fields', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    agent(tools: [new NonStrictTool()])->prompt('Hi', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();

        return $tool['strict'] === false
            && $tool['parameters']['required'] === ['query']
            && array_key_exists('limit', $tool['parameters']['properties']);
    });
});

test('tool with empty schema includes strict compliant parameters', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a random number', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();

        return $tool['strict'] === true
            && array_key_exists('parameters', $tool)
            && $tool['parameters']['type'] === 'object'
            && $tool['parameters']['properties'] === []
            && $tool['parameters']['required'] === []
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('web search tool sends type web_search', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch()])->prompt('Search the web', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return $tool !== null;
    });
});

test('web search tool omits openai-specific options by default', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch()])->prompt('Search the web', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return ! array_key_exists('external_web_access', $tool);
    });
});

test('web search tool forwards openai provider options into the tool payload', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch())->withProviderOptions([
            'external_web_access' => false,
            'search_context_size' => 'high',
        ]),
    ])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return Hash::get($tool, 'external_web_access') === false
            && Hash::get($tool, 'search_context_size') === 'high';
    });
});

test('web search tool ignores provider options keyed to another provider', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch())->withProviderOptions(fn(Lab|string $provider): array => $provider === Lab::Anthropic
            ? ['external_web_access' => false]
            : []),
    ])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return ! array_key_exists('external_web_access', $tool);
    });
});

test('web search tool sends allowed_domains filter', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [(new WebSearch())->allow(['example.com', 'docs.example.com'])])
        ->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return Hash::get($tool, 'filters.allowed_domains') === ['example.com', 'docs.example.com'];
    });
});

test('web search tool sends blocked_domains via provider options', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch())->withProviderOptions([
            'filters' => ['blocked_domains' => ['spam.com', 'ads.example.com']],
        ]),
    ])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return Hash::get($tool, 'filters.blocked_domains') === ['spam.com', 'ads.example.com'];
    });
});

test('web search tool merges allow() with blocked_domains provider option', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [
        (new WebSearch())
            ->allow(['good.com'])
            ->withProviderOptions(['filters' => ['blocked_domains' => ['bad.com']]]),
    ])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return Hash::get($tool, 'filters.allowed_domains') === ['good.com']
            && Hash::get($tool, 'filters.blocked_domains') === ['bad.com'];
    });
});

test('web search tool omits filters when no domains configured', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch()])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return ! array_key_exists('filters', $tool);
    });
});

test('web search tool sends user_location when location is set', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [(new WebSearch())->location(city: 'Warsaw', country: 'PL')])
        ->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return Hash::get($tool, 'user_location.type') === 'approximate'
            && Hash::get($tool, 'user_location.city') === 'Warsaw'
            && Hash::get($tool, 'user_location.country') === 'PL';
    });
});

test('web search tool omits user_location when no location set', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('result'),
    ]);

    agent(tools: [new WebSearch()])->prompt('Search', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'web_search')->first();

        return ! array_key_exists('user_location', $tool);
    });
});
