<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Providers\Tools\ToolSearch;
use Crustum\Ai\Test\Fixtures\Agents\OpenAiToolSearchAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Trait\PromptableTrait;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('an agent with a ToolSearch tool emits a tool_search entry and defers its nested tools', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    (new OpenAiToolSearchAgent())->prompt('Hi');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $tools = collect(Hash::get(json_decode($request->body(), true), 'tools'));

        $deferred = $tools->filter(fn($t): bool => ($t['name'] ?? null) === 'DeferredTool')->first();
        $plain = $tools->filter(fn($t): bool => ($t['name'] ?? null) === 'NonStrictTool')->first();

        return $tools->some(fn($t): bool => ($t['type'] ?? null) === 'tool_search')
            && ($deferred['defer_loading'] ?? false) === true
            && !isset($plain['defer_loading']);
    });
});

test('rejects a ToolSearch tool when response storage is disabled', function (): void {
    Configure::write('Ai.providers.openai.store', false);

    aiHttpFake(['*' => fakeOpenAiResponse('ok')]);

    (new OpenAiToolSearchAgent())->prompt('Find the secret', provider: 'openai');

    aiAssertHttpNothingSent();
})->throws(LogicException::class, 'store=false');

test('an agent whose only tool is an empty ToolSearch omits the tool fields', function (): void {
    aiHttpFake([
        '*' => fakeOpenAiResponse('ok'),
    ]);

    $agent = new class implements Agent, HasTools
    {
        use PromptableTrait;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new ToolSearch()];
        }
    };

    $agent->prompt('Hi', provider: 'openai', model: 'gpt-5.4');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('tools', $body)
            && !array_key_exists('tool_choice', $body);
    });
});
