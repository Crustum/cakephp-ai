<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Providers\OpenAiCompatibleProvider;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai-compatible', [
        'className' => OpenAiCompatibleProvider::class,
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1/',
        'key' => 'test-key',
        'models' => [
            'classification' => ['default' => 'local-classifier'],
        ],
    ]);
});

test('classification posts to the decisions endpoint of the configured url', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'answers' => [['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.92]],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 0],
    ])]);

    $response = Classification::of('I was charged twice.')
        ->question('is_urgent', new Boolean('Is this urgent?'))
        ->classify(provider: 'openai-compatible');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'http://localhost:1234/v1/decisions'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && json_decode($request->body(), true) === [
            'model' => 'local-classifier',
            'input' => 'I was charged twice.',
            'questions' => [
                ['type' => 'predicate', 'name' => 'is_urgent', 'instructions' => 'Is this urgent?'],
            ],
        ]);

    expect($response['is_urgent']->probability)->toBe(0.92)
        ->and($response->usage->inputTokens)->toBe(12)
        ->and($response->meta->model)->toBe('local-classifier');
});

test('classification omits the authorization header when no key is configured', function (): void {
    Configure::write('Ai.providers.openai-compatible.key');

    aiHttpFake(['*' => aiHttpResponse(['answers' => []])]);

    Classification::of('text')
        ->question('is_urgent', new Boolean('Urgent?'))
        ->classify(provider: 'openai-compatible');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => !$request->hasHeader('Authorization'));
});

test('classification sends image attachments inline', function (): void {
    aiHttpFake(['*' => aiHttpResponse(['answers' => []])]);

    Classification::of('Inspect this.', [Image::fromBase64(base64_encode('bytes'), 'image/png')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'openai-compatible');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['input'][0]['content'][1] === [
        'type' => 'input_image',
        'image_url' => 'data:image/png;base64,' . base64_encode('bytes'),
    ]);
});

test('classification throws when no default model is configured and none is passed', function (): void {
    Configure::write('Ai.providers.openai-compatible.models', []);

    aiHttpFake();

    expect(fn(): ClassificationResponse => Classification::of('text')
        ->question('is_urgent', new Boolean('Urgent?'))
        ->classify(provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'requires a default classification model');

    aiAssertHttpNothingSent();
});
