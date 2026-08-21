<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\RemoteDocument;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\ToolResultMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Responses\Data\ToolCall;
use Crustum\Ai\Responses\Data\ToolResult;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.xai', [

        ...(array)Configure::read('Ai.providers.xai'),
        'key' => 'test-key',
    ]);
});

test('user message maps to responses api format', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent())->prompt(IntegrationPrompts::question('knowledge'), provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return $userMsg !== null
            && collect($userMsg['content'])->some(
                fn($c): bool => ($c['type'] ?? '') === 'input_text' && $c['text'] === IntegrationPrompts::question('knowledge'),
            );
    });
});

test('tool call follow up uses previous response id', function (): void {
    aiHttpFake([
        '*' => aiHttpSequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'xai');

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->not->toBeEmpty();

    $hasToolOutput = collect($followUpBody['input'])->some(
        fn($item): bool => ($item['type'] ?? '') === 'function_call_output',
    );

    expect($hasToolOutput)->toBeTrue('Follow-up should include function_call_output');
});

test('remote image attachment maps to input image', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new RemoteImage('https://example.com/image.png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'xai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        $imageBlock = collect($userMsg['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'input_image')->first();

        return $imageBlock !== null
            && $imageBlock['image_url'] === 'https://example.com/image.png';
    });
});

test('base64 image attachment maps to data uri', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'xai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        $imageBlock = collect($userMsg['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'input_image')->first();

        return $imageBlock !== null
            && str_starts_with((string)$imageBlock['image_url'], 'data:image/png;base64,');
    });
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__ . '/../../../Fixtures/Images/red.png')],
        provider: 'xai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $imageBlock = collect($userMsg['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'input_image')->first();

        return $imageBlock !== null
            && str_starts_with((string)$imageBlock['image_url'], 'data:image/png;base64,')
            && ! str_contains((string)$imageBlock['image_url'], 'data:;base64,');
    });
});

test('remote document maps to input file', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = new RemoteDocument('https://example.com/report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'xai',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        $fileBlock = collect($userMsg['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'input_file')->first();

        return $fileBlock !== null
            && $fileBlock['file_url'] === 'https://example.com/report.pdf';
    });
});

test('reasoning blocks are interleaved with associated tool calls on assistant replay', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse('hi')]);

    agent(
        instructions: 'Hi.',
        messages: [
            new UserMessage('search'),
            new AssistantMessage('Searching.', collect([
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
            new ToolResultMessage(collect([
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
    )->prompt('', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $input = $body['input'];

        $rs1Index = searchIndex($input, fn($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_1');
        $call1Index = searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_1');
        $rs2Index = searchIndex($input, fn($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_2');
        $call2Index = searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_2');
        $call3Index = searchIndex($input, fn($i): bool => ($i['id'] ?? '') === 'call_3');

        return $rs1Index !== false
            && $call1Index !== false
            && $rs1Index + 1 === $call1Index
            && $rs2Index !== false
            && $call2Index !== false
            && $rs2Index + 1 === $call2Index
            && $call3Index !== false;
    });
});

test('system instructions are in input array', function (): void {
    aiHttpFake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent())->prompt('Hi', provider: 'xai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'system')->first();

        return $systemMsg !== null
            && str_contains((string)$systemMsg['content'], 'helpful assistant');
    });
});

function searchIndex(array $items, callable $callback): int|false
{
    foreach ($items as $index => $item) {
        if ($callback($item)) {
            return $index;
        }
    }

    return false;
}
