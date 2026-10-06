<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\AnthropicToolSearchAgent;

test('an agent with a ToolSearch tool emits the regex tool search entry and defers its nested tools', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new AnthropicToolSearchAgent())->prompt('Hi');

    aiAssertHttpSent(function ($request): bool {
        $tools = collect($request->data()['tools'] ?? []);

        $deferred = $tools->filter(fn($t): bool => ($t['name'] ?? null) === 'DeferredTool')->first();
        $plain = $tools->filter(fn($t): bool => ($t['name'] ?? null) === 'NonStrictTool')->first();

        return $tools->some(fn($t): bool => ($t['type'] ?? null) === 'tool_search_tool_regex_20251119')
            && ($deferred['defer_loading'] ?? false) === true
            && !isset($plain['defer_loading']);
    });
});
