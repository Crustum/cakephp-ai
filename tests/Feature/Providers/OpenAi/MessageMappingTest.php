<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\LocalDocument;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

test('user message maps to openai format', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'What is CakePHP?',
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $input = $body['input'];
        $userMessage = collect($input)->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();

        return $userMessage !== null
            && $userMessage['content'][0]['type'] === 'input_text'
            && $userMessage['content'][0]['text'] === 'What is CakePHP?';
    });
});

test('tool result follow up uses previous response id', function (): void {
    aiHttpFake([
        'api.openai.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'openai',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->toBe('resp_tool_123');

    $hasFunctionCallOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasFunctionCallOutput = true;
            expect($item['call_id'])->toBe('call_123')
                ->and($item['output'])->not->toBeEmpty();
        }
    }

    expect($hasFunctionCallOutput)->toBeTrue();
});

test('base64 pdf document maps to input file', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $content = $userMessage['content'];

        $fileBlock = collect($content)->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_file')->first();

        return $fileBlock !== null
            && str_contains($fileBlock['file_data'], 'application/pdf')
            && str_contains($fileBlock['file_data'], base64_encode('fake-pdf-content'));
    });
});

test('nameless text document falls back to derived filename', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [Files\Document::fromString('hello world', 'text/plain')],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $fileBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_file')->first();

        return $fileBlock !== null
            && ($fileBlock['filename'] ?? null) === 'document.txt';
    });
});

test('uploaded pdf file maps to input file', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
    ]);

    $path = TMP . 'report_' . uniqid() . '.pdf';
    file_put_contents($path, str_repeat('x', 100));

    try {
        $file = new LocalDocument($path, 'application/pdf');

        agent('You are helpful.')->prompt(
            'What is in this file?',
            attachments: [$file],
            provider: 'openai',
        );

        aiAssertHttpSent(function (AiHttpRequest $request): bool {
            $body = json_decode($request->body(), true);
            $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
            $content = $userMessage['content'];

            $fileBlock = collect($content)->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_file')->first();

            return $fileBlock !== null
                && str_contains($fileBlock['file_data'], 'application/pdf');
        });
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_image')->first();

        return $imageBlock !== null
            && str_starts_with($imageBlock['image_url'], 'data:image/png;base64,')
            && ! str_contains($imageBlock['image_url'], 'data:;base64,');
    });
});

test('empty tool arguments serialize as object string on assistant replay', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        messages: [
            new UserMessage('list'),
            new AssistantMessage('Listing.', collection([
                new ToolCall(
                    id: 'call_empty',
                    name: 'FixedNumberGenerator',
                    arguments: [],
                    resultId: 'call_empty',
                ),
            ])),
            new ToolResultMessage(collection([
                new ToolResult(
                    id: 'call_empty',
                    name: 'FixedNumberGenerator',
                    arguments: [],
                    result: '42',
                    resultId: 'call_empty',
                ),
            ])),
            new UserMessage('thanks'),
        ],
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
    )->prompt('', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $fnCall = collect($body['input'] ?? [])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function_call')->first();

        return $fnCall && $fnCall['arguments'] === '{}';
    });
});

test('non-empty tool arguments preserve shape on assistant replay', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        messages: [
            new UserMessage('search'),
            new AssistantMessage('Searching.', collection([
                new ToolCall(
                    id: 'call_args',
                    name: 'FixedNumberGenerator',
                    arguments: ['query' => 'test'],
                    resultId: 'call_args',
                ),
            ])),
            new ToolResultMessage(collection([
                new ToolResult(
                    id: 'call_args',
                    name: 'FixedNumberGenerator',
                    arguments: ['query' => 'test'],
                    result: '42',
                    resultId: 'call_args',
                ),
            ])),
            new UserMessage('thanks'),
        ],
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
    )->prompt('', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $fnCall = collect($body['input'] ?? [])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'function_call')->first();

        return $fnCall && json_decode($fnCall['arguments'], true) === ['query' => 'test'];
    });
});

test('reasoning blocks are interleaved with associated tool calls on assistant replay', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        messages: [
            new UserMessage('search'),
            new AssistantMessage('Searching.', collection([
                new ToolCall(
                    id: 'call_1',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'foo'],
                    resultId: 'call_1',
                    reasoningId: 'rs_1',
                    reasoningSummary: [],
                ),
                new ToolCall(
                    id: 'call_2',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'bar'],
                    resultId: 'call_2',
                    reasoningId: 'rs_2',
                    reasoningSummary: [],
                ),
                new ToolCall(
                    id: 'call_3',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'baz'],
                    resultId: 'call_3',
                ),
            ])),
            new ToolResultMessage(collection([
                new ToolResult(
                    id: 'call_1',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'foo'],
                    result: '42',
                    resultId: 'call_1',
                ),
            ])),
            new UserMessage('thanks'),
        ],
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
    )->prompt('', provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $input = $body['input'];

        $searchIndex = function (array $items, callable $callback): int|false {
            foreach ($items as $index => $item) {
                if ($callback($item)) {
                    return $index;
                }
            }

            return false;
        };

        $rs1Index = $searchIndex($input, fn($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_1');
        $call1Index = $searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_1');
        $rs2Index = $searchIndex($input, fn($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_2');
        $call2Index = $searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_2');
        $call3Index = $searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_3');

        return $rs1Index !== false
            && $call1Index !== false
            && $rs1Index + 1 === $call1Index
            && $rs2Index !== false
            && $call2Index !== false
            && $rs2Index + 1 === $call2Index
            && $call3Index !== false;
    });
});

test('image attachment provider options are forwarded to the content part', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
        ->withProviderOptions(['detail' => 'low']);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_image')->first();

        return $imageBlock !== null
            && ($imageBlock['detail'] ?? null) === 'low'
            && str_starts_with($imageBlock['image_url'], 'data:image/png;base64,');
    });
});

test('document attachment provider options are forwarded to the content part', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a document'),
    ]);

    $document = Files\Document::fromString('hello world', 'text/plain')
        ->withProviderOptions(['detail' => 'high']);

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$document],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $fileBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_file')->first();

        return $fileBlock !== null
            && ($fileBlock['detail'] ?? null) === 'high'
            && str_contains($fileBlock['file_data'], base64_encode('hello world'));
    });
});

test('attachment provider options resolve from a closure scoped to the provider', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
        ->withProviderOptions(fn(Lab $provider): array => match ($provider) {
            Lab::OpenAI => ['detail' => 'low'],
            default => [],
        });

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_image')->first();

        return $imageBlock !== null
            && ($imageBlock['detail'] ?? null) === 'low';
    });
});

test('attachments without provider options map unchanged', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_image')->first();

        return $imageBlock !== null
            && ! array_key_exists('detail', $imageBlock);
    });
});

test('provider options cannot overwrite the mapped structural keys', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    $image = (new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png'))
        ->withProviderOptions(['type' => 'input_text', 'detail' => 'low']);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($item): bool => is_array($item) && array_key_exists('type', $item) && $item['type'] === 'input_image')->first();

        return $imageBlock !== null
            && $imageBlock['type'] === 'input_image'
            && ($imageBlock['detail'] ?? null) === 'low'
            && str_starts_with($imageBlock['image_url'], 'data:image/png;base64,');
    });
});

test('system instructions are in input array as system role', function (): void {
    aiHttpFake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    (new AssistantAgent())->prompt(
        'Hi',
        provider: 'openai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->filter(fn($item): bool => is_array($item) && array_key_exists('role', $item) && $item['role'] === 'system')->first();

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});
