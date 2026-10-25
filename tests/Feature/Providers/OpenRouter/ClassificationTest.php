<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use Crustum\Ai\Responses\Data\ScoreAnswer;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Laminas\Diactoros\UploadedFile;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

function classificationUpload(string $filename, string $contents, string $mimeType): UploadedFile
{
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, $contents);
    rewind($resource);

    return new UploadedFile($resource, strlen($contents), UPLOAD_ERR_OK, $filename, $mimeType);
}

function fakeDecisionsResponse(): array
{
    return [
        'id' => 'dec_123',
        'model' => 'typesafe/jev-1.13',
        'provider' => 'TypeSafe',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.96],
            'department' => [
                'type' => 'choice',
                'choice' => 'payments',
                'probabilities' => ['account' => 0.0, 'frontend' => 0.16, 'payments' => 0.84],
                'confidence' => 0.75,
            ],
            'urgency' => [
                'type' => 'score',
                'score' => 1.99,
                'probabilities' => ['0' => 0.0, '1' => 0.01, '2' => 0.99],
                'legend' => ['0' => 'Next release', '1' => 'This week', '2' => 'Blocking revenue'],
                'confidence' => 0.99,
            ],
        ],
        'usage' => ['input_tokens' => 312, 'output_tokens' => 48, 'cost' => 0.000013],
    ];
}

test('classification posts to the decisions endpoint in the system one wire format', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions([
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should own this ticket?', [
                'account' => 'Login, permissions, or profile issues.',
                'frontend' => 'Rendering or layout issues.',
                'payments' => null,
            ]),
            'urgency' => new Score('How urgent is this ticket?', [
                'Next release', 'This week', 'Blocking revenue',
            ]),
        ])
        ->classify(provider: 'openrouter', model: 'typesafe/jev-1.13');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://openrouter.ai/api/alpha/decisions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'typesafe/jev-1.13'
            && $body['state'] === 'Stripe connect keeps failing, losing sales, help ASAP'
            && $body['questions'] === [
                'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should own this ticket?',
                    'criteria' => ['account' => 'Login, permissions, or profile issues.', 'frontend' => 'Rendering or layout issues.', 'payments' => null],
                ],
                'urgency' => [
                    'type' => 'score',
                    'instructions' => 'How urgent is this ticket?',
                    'criteria' => ['Next release', 'This week', 'Blocking revenue'],
                ],
            ];
    });
});

test('decisions response is parsed into typed answers', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    $response = Classification::of('text')
        ->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Team?', ['account' => null, 'frontend' => null, 'payments' => null]),
            'urgency' => new Score('How urgent?', ['Next release', 'This week', 'Blocking revenue']),
        ])
        ->classify(provider: 'openrouter');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBe(0.96)
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('payments')
        ->and($response['department']->probabilityOf('frontend'))->toBe(0.16)
        ->and($response['urgency'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['urgency']->score)->toBe(1.99)
        ->and($response['urgency']->level())->toBe(2)
        ->and($response['urgency']->label())->toBe('Blocking revenue')
        ->and($response->usage->inputTokens)->toBe(312)
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('typesafe/jev-1.13');
});

test('classification uses the default decisions model when none is specified', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === '~typesafe/jev-latest');
});

test('decisions requests use the configured base url', function (): void {
    Configure::write('Ai.providers.openrouter.url', 'http://localhost:8080/api/v1');

    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'http://localhost:8080/api/alpha/decisions');

    Configure::delete('Ai.providers.openrouter.url');
});

test('answers without the optional distribution fields fall back to the questions asked', function (): void {
    aiHttpFake(['*' => aiHttpResponse([
        'model' => 'typesafe/jev-1.13',
        'answers' => [
            'department' => ['type' => 'choice', 'choice' => 'payments'],
            'urgency' => ['type' => 'score', 'score' => 2.0],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
    ])]);

    $response = Classification::of('text')
        ->questions([
            'department' => new Choice('Team?', ['account' => null, 'payments' => null]),
            'urgency' => new Score('How urgent?', ['Next release', 'This week', 'Blocking revenue']),
        ])
        ->classify(provider: 'openrouter');

    expect($response['department']->probabilities)->toBe([])
        ->and($response['department']->probabilityOf('payments'))->toBe(0.0)
        ->and($response['department']->confidence)->toBeNull()
        ->and($response['urgency']->probabilities)->toBe([])
        ->and($response['urgency']->legend)->toBe(['Next release', 'This week', 'Blocking revenue'])
        ->and($response['urgency']->label())->toBe('Blocking revenue')
        ->and($response['urgency']->normalized())->toBe(1.0);
});

test('image attachments are appended to the state as data url image parts labelled by name', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');

    Classification::of('A product photo uploaded by a customer.', [
        'front' => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg')->withProviderOptions(
            fn($provider): ?array => $provider === Lab::OpenRouter ? ['custom' => 'value'] : null,
        ),
        'back' => classificationUpload('back.png', 'upload-bytes', 'image/png'),
    ])
        ->question('damaged', new Boolean('Is the product damaged?'))
        ->classify(provider: 'openrouter');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://openrouter.ai/api/alpha/decisions'
        && !$request->hasHeader('TypeSafe-Danger-Zone-Images')
        && json_decode($request->body(), true)['state'] === [
            'A product photo uploaded by a customer.',
            'front:',
            ['custom' => 'value', 'type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
            'back:',
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('upload-bytes')]],
        ]);
});

test('image parts follow the items of a list state and the object of a structured state', function (array $state, array $expected): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    Classification::of($state, [Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'openrouter');

    $part = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('photo')]];

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['state'] === [...$expected, $part]);
})->with([
    'list' => [['first message', 'second message'], ['first message', 'second message']],
    'object' => [['subject' => 'Broken lamp'], [['subject' => 'Broken lamp']]],
]);

test('webp images are sent as webp data urls', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeDecisionsResponse())]);

    Classification::of('Inspect this.', [classificationUpload('photo.webp', 'webp-bytes', 'image/webp')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'openrouter', model: 'cloudflare/clef-flash');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['state'] === [
        'Inspect this.',
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,' . base64_encode('webp-bytes')]],
    ]);
});

test('attachments that are not inline jpeg, png, or webp images are rejected before any request', function (mixed $attachment, string $message): void {
    aiHttpFake();

    expect(fn(): ClassificationResponse => Classification::of('Inspect this.', [$attachment()])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'openrouter'))->toThrow(InvalidArgumentException::class, $message);

    aiAssertHttpNothingSent();
})->with([
    'provider image' => [fn(): ProviderImage => Image::fromId('file_123'), 'OpenRouter decisions only accept images with inline content; [Crustum\\Ai\\Files\\ProviderImage] given.'],
    'gif upload' => [fn(): UploadedFile => classificationUpload('photo.gif', 'gif-bytes', 'image/gif'), 'OpenRouter decisions only accept JPEG, PNG, or WebP images; [image/gif] given.'],
]);
