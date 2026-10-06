<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Attributes\CacheConversation;
use Crustum\Ai\Attributes\CacheInstructions;
use Crustum\Ai\Attributes\CacheToolDefinitions;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\PromptCacheAgent;
use Crustum\Ai\Test\Fixtures\Agents\PromptCacheStructuredAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Http\AiHttpResponseDefinition;

beforeEach(function (): void {
    Configure::write('Ai.providers.anthropic.key', 'test-key');
});

test('cache instructions attribute converts instructions to a cached block', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] class (withTools: false) extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['text'] === 'You are a helpful assistant that generates numbers.'
            && end($system)['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('cache tool definitions attribute stamps the last tool', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('42')]);

    (new #[CacheToolDefinitions] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tools = $body['tools'] ?? [];

        return is_array($tools)
            && $tools !== []
            && end($tools)['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('both targets may be cached together', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];
        $tools = $body['tools'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['cache_control']['type'] === 'ephemeral'
            && is_array($tools)
            && $tools !== []
            && end($tools)['cache_control']['type'] === 'ephemeral';
    });
});

test('cache conversation attribute stamps the last message block', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheConversation] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'] ?? [];
        $last = end($messages);
        $content = $last['content'] ?? [];

        return is_array($content)
            && $content !== []
            && end($content)['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('cache conversation attribute honors an explicit ttl', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheConversation('5m')] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'] ?? [];
        $last = end($messages);
        $content = $last['content'] ?? [];

        return is_array($content)
            && $content !== []
            && end($content)['cache_control'] === ['type' => 'ephemeral', 'ttl' => '5m'];
    });
});

test('cache conversation attribute marks the most recent message, not earlier ones', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    $agent = new #[CacheConversation] class extends PromptCacheAgent implements Conversational {
        public function messages(): iterable
        {
            return [new UserMessage('Earlier'), new AssistantMessage('Reply')];
        }
    };

    $agent->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'] ?? [];

        if (count($messages) < 3) {
            return false;
        }

        $first = $messages[0]['content'] ?? [];
        $last = end($messages)['content'] ?? [];

        return is_array($first)
            && !isset(end($first)['cache_control'])
            && is_array($last)
            && end($last)['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('the synthetic structured output tool receives the breakpoint', function (): void {
    Configure::write('Ai.providers.anthropic.use_native_structured_output', false);

    aiHttpFake(['*' => aiHttpResponse([
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [
            ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'output_structured_data', 'input' => ['symbol' => 'Fe']],
        ],
        'model' => 'claude-sonnet-4-20250514',
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    (new PromptCacheStructuredAgent())->prompt('Iron', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $tools = $body['tools'] ?? [];

        return is_array($tools)
            && $tools !== []
            && end($tools)['name'] === 'output_structured_data'
            && end($tools)['cache_control']['type'] === 'ephemeral';
    });
});

test('an agent without cache attributes leaves the payload untouched', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new AssistantAgent())->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? null;
        $tools = $body['tools'] ?? [];

        if (is_string($system)) {
            return $tools === [] || !isset(end($tools)['cache_control']);
        }

        return false;
    });
});

test('provider options still merge alongside cache attributes', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] class (options: ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]) extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['cache_control']['type'] === 'ephemeral'
            && ($body['thinking']['budget_tokens'] ?? null) === 10000;
    });
});

test('a target may request the extended ttl', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions('5m')] #[CacheToolDefinitions('1h')] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];
        $tools = $body['tools'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['cache_control'] === ['type' => 'ephemeral', 'ttl' => '5m']
            && is_array($tools)
            && $tools !== []
            && end($tools)['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
    });
});

test('an omitted ttl uses the provider default', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('a longer instructions ttl requires the tools cache to use the same ttl', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');
})->throws(InvalidArgumentException::class);

test('a longer automatic cache ttl requires explicit breakpoints to use the same ttl', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] class (options: ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]) extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');
})->throws(InvalidArgumentException::class);

test('breakpoints survive provider options that override the same keys', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new #[CacheInstructions] #[CacheToolDefinitions] class (options: ['system' => 'Overridden instructions.', 'tools' => [['name' => 'custom', 'description' => '', 'input_schema' => ['type' => 'object']]]]) extends PromptCacheAgent {
    })->prompt('Hi', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $system = $body['system'] ?? [];
        $tools = $body['tools'] ?? [];

        return is_array($system)
            && $system !== []
            && end($system)['text'] === 'Overridden instructions.'
            && end($system)['cache_control']['type'] === 'ephemeral'
            && is_array($tools)
            && $tools !== []
            && end($tools)['name'] === 'custom'
            && end($tools)['cache_control']['type'] === 'ephemeral';
    });
});

test('the tools target is a no-op when the request carries no tools', function (): void {
    aiHttpFake(['*' => fakeAnthropicResponse('Hello')]);

    (new PromptCacheStructuredAgent())->prompt('Iron', provider: 'anthropic');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return !array_key_exists('tools', $body);
    });
});

test('the streaming path stamps the same breakpoints', function (): void {
    aiHttpFake([
        'api.anthropic.com/*' => aiHttpResponse(
            body: $this->ssePayload([
                $this->messageStart(),
                $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hi']),
                $this->contentBlockStop(0),
                $this->messageDelta('end_turn', 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents(new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {
    });

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return isset($body['system'][0]['cache_control'])
            && end($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
    });
});

function fakeAnthropicResponse(string $text): AiHttpResponseDefinition
{
    return aiHttpResponse([
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [
            ['type' => 'text', 'text' => $text],
        ],
        'model' => 'claude-sonnet-4-20250514',
        'stop_reason' => 'end_turn',
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
