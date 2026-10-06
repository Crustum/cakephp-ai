<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Providers\Tools\WebFetch;
use Crustum\Ai\Providers\Tools\WebSearch;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Fixtures\Tools\NamedTool;
use Crustum\Ai\Test\Fixtures\Tools\RandomNumberGenerator;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

test('tool with parameters includes correct schema', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('42')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();
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
    aiHttpFake(['*' => fakeOpenRouterResponse('72019')]);

    agent(tools: [new FixedNumberGenerator()])->prompt('Give me a random number', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();
        $function = $tool['function'] ?? [];

        return array_key_exists('parameters', $function)
            && $function['parameters']['type'] === 'object'
            && $function['parameters']['properties'] === []
            && $function['parameters']['required'] === []
            && $function['parameters']['additionalProperties'] === false;
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new RandomNumberGenerator()])->prompt('Give me a random number', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function')->first();
        $function = $tool['function'] ?? [];

        return ! array_key_exists('schema_definition', $function['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $function['parameters']['required'] ?? []);
    });
});

test('web fetch tool is sent as openrouter:web_fetch type', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebFetch()])->prompt('Read https://cakephp.org', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_fetch')->first();

        return $tool !== null && ! array_key_exists('parameters', $tool);
    });
});

test('web fetch tool sends max_uses when maxSearches is set', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebFetch())->max(5)])->prompt('Read https://cakephp.org', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_fetch')->first();

        return Hash::get($tool, 'parameters.max_uses') === 5;
    });
});

test('web fetch tool sends allowed_domains', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebFetch())->allow(['example.com', 'cakephp.org'])])->prompt('Read the docs', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_fetch')->first();

        return Hash::get($tool, 'parameters.allowed_domains') === ['example.com', 'cakephp.org'];
    });
});

test('web fetch tool forwards provider options into parameters', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    $fetch = (new WebFetch())->withProviderOptions([
        'engine' => 'exa',
        'max_content_tokens' => 50000,
        'blocked_domains' => ['private.example.com'],
    ]);

    agent(tools: [$fetch])->prompt('Read the docs', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_fetch')->first();

        return Hash::get($tool, 'parameters.engine') === 'exa'
            && Hash::get($tool, 'parameters.max_content_tokens') === 50000
            && Hash::get($tool, 'parameters.blocked_domains') === ['private.example.com'];
    });
});

test('web fetch and web search can be used together', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebSearch(), new WebFetch()])->prompt('Research this', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $types = collect(Hash::get(json_decode($request->body(), true), 'tools'))->extract('type')->toList();

        return in_array('openrouter:web_search', $types, true)
            && in_array('openrouter:web_fetch', $types, true);
    });
});

test('web search tool is sent as openrouter:web_search type', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [new WebSearch()])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return $tool !== null && ! array_key_exists('parameters', $tool);
    });
});

test('web search tool sends max_uses when maxSearches is set', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebSearch())->max(5)])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return Hash::get($tool, 'parameters.max_uses') === 5;
    });
});

test('web search tool forwards max_results through provider options', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    $search = (new WebSearch())->max(3)->withProviderOptions(['max_results' => 10]);

    agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return Hash::get($tool, 'parameters.max_uses') === 3
            && Hash::get($tool, 'parameters.max_results') === 10;
    });
});

test('web search tool sends allowed_domains', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebSearch())->allow(['example.com', 'cakephp.org'])])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return Hash::get($tool, 'parameters.allowed_domains') === ['example.com', 'cakephp.org'];
    });
});

test('web search tool sends user_location', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    agent(tools: [(new WebSearch())->location(city: 'San Francisco', country: 'US')])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return Hash::get($tool, 'parameters.user_location') === [
            'type' => 'approximate',
            'city' => 'San Francisco',
            'country' => 'US',
        ];
    });
});

test('web search tool forwards provider options into parameters', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('done')]);

    $search = (new WebSearch())->withProviderOptions([
        'engine' => 'exa',
        'max_total_results' => 20,
        'search_context_size' => 'medium',
        'excluded_domains' => ['reddit.com'],
    ]);

    agent(tools: [$search])->prompt('Search the web', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(Hash::get($body, 'tools'))->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'openrouter:web_search')->first();

        return Hash::get($tool, 'parameters.engine') === 'exa'
            && Hash::get($tool, 'parameters.max_total_results') === 20
            && Hash::get($tool, 'parameters.search_context_size') === 'medium'
            && Hash::get($tool, 'parameters.excluded_domains') === ['reddit.com'];
    });
});

test('tool with a name() method emits the declared name', function (): void {
    aiHttpFake(['*' => fakeOpenRouterResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'openrouter');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(Hash::get($body, 'tools'))->extract('function.name')->toList();

        return in_array('my_custom_tool', $names, true);
    });
});
