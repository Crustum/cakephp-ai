<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\Base64Document;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\IntegrationPrompts;

beforeEach(function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]);
});

test('user message maps to azure format', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse(),
    ]);

    (new AssistantAgent())->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: 'azure',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $input = $body['input'];
        $userMessage = collect($input)->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();

        return $userMessage !== null
            && $userMessage['content'][0]['type'] === 'input_text'
            && $userMessage['content'][0]['text'] === IntegrationPrompts::question('knowledge');
    });
});

test('tool result follow up uses previous response id', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'azure',
    );

    $recorded = aiHttpRecorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->toBe('resp_azure_tool_123');

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

test('azure store false enables stateless inline conversation', function (): void {
    Configure::write('Ai.providers.azure', [

        ...(array)Configure::read('Ai.providers.azure'),
        'store' => false,
    ]);

    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => aiHttpSequence([
            fakeOpenAiToolCallResponse('resp_azure_tool_123', 'gpt-4o'),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'azure');

    $recorded = aiHttpRecorded();
    $initialBody = json_decode((string)$recorded[0][0]->body(), true);
    $followUpBody = json_decode((string)$recorded[1][0]->body(), true);

    expect($initialBody['store'] ?? null)->toBeFalse()
        ->and($followUpBody)->not->toHaveKey('previous_response_id')
        ->and($followUpBody['store'] ?? null)->toBeFalse();
});

test('image attachment maps to input_image content block', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'azure',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $content = $userMessage['content'];

        $imageBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'input_image')->first();

        return $imageBlock !== null
            && str_contains((string)$imageBlock['image_url'], 'image/png')
            && str_contains((string)$imageBlock['image_url'], base64_encode('fake-image-data'));
    });
});

test('attachment provider options closure receives the azure provider', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see an image'),
    ]);

    $image = (new Base64Image(base64_encode('fake-image-data'), 'image/png'))
        ->withProviderOptions(fn(Lab $provider): array => match ($provider) {
            Lab::Azure => ['detail' => 'low'],
            default => ['detail' => 'high'],
        });

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'azure',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $imageBlock = collect($userMessage['content'])->filter(fn($m): bool => ($m['type'] ?? null) === 'input_image')->first();

        return $imageBlock !== null
            && ($imageBlock['detail'] ?? null) === 'low';
    });
});

test('document attachment maps to input_file content block', function (): void {
    aiHttpFake([
        'my-resource.cognitiveservices.azure.com/*' => fakeAzureResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'azure',
    );

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->filter(fn($m): bool => ($m['role'] ?? null) === 'user')->first();
        $content = $userMessage['content'];

        $fileBlock = collect($content)->filter(fn($m): bool => ($m['type'] ?? null) === 'input_file')->first();

        return $fileBlock !== null
            && str_contains((string)$fileBlock['file_data'], 'application/pdf');
    });
});
