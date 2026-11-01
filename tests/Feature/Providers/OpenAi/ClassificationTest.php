<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Files\Image;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Files\ProviderImage;
use Crustum\Ai\Files\RemoteImage;
use Crustum\Ai\Http\Response;
use Crustum\Ai\Responses\ClassificationResponse;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;
use Crustum\Ai\Test\Support\Storage\LocalDisk;
use Laminas\Diactoros\UploadedFile;

beforeEach(function (): void {
    Configure::write('Ai.providers.openai.key', 'test-key');
});

function fakeOpenAiDecisionsResponse(): array
{
    return [
        'model' => 'gpt-6-luna',
        'answers' => [
            ['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.92],
            [
                'type' => 'choice',
                'name' => 'department',
                'choice' => 'billing',
                'probabilities' => [
                    ['value' => 'billing', 'probability' => 0.95],
                    ['value' => 'shipping', 'probability' => 0.05],
                ],
                'confidence' => 0.93,
            ],
            [
                'type' => 'score',
                'name' => 'severity',
                'score' => 1.1,
                'probabilities' => [
                    ['value' => 0, 'label' => 'Cosmetic', 'probability' => 0.1],
                    ['value' => 1, 'label' => 'Workaround available', 'probability' => 0.7],
                    ['value' => 2, 'label' => 'Fully blocked', 'probability' => 0.2],
                ],
                'confidence' => 0.55,
            ],
            ['type' => 'refusal', 'name' => 'refused'],
        ],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 0],
    ];
}

test('classification posts questions to the decisions endpoint in the openai wire format', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    Classification::of('I was charged twice for my order.')
        ->questions([
            'is_urgent' => new Boolean('Is this urgent?', ['true' => 'Money was lost.', 'false' => 'A question only.']),
            'department' => new Choice('Which department?', [
                'billing' => 'Payments and refunds.',
                'shipping' => null,
            ]),
            'severity' => new Score(['ask' => 'How severe?'], [
                'Cosmetic',
                ['label' => 'Workaround available', 'description' => 'Usable with extra steps.'],
                ['impact' => 'total'],
            ]),
        ])
        ->classify(provider: 'openai');

    aiAssertHttpSent(function (AiHttpRequest $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/decisions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body === [
                'model' => 'gpt-6-luna',
                'input' => 'I was charged twice for my order.',
                'questions' => [
                    [
                        'type' => 'predicate',
                        'name' => 'is_urgent',
                        'instructions' => "Is this urgent?\n\nTrue: Money was lost.\n\nFalse: A question only.",
                    ],
                    [
                        'type' => 'choice',
                        'name' => 'department',
                        'instructions' => 'Which department?',
                        'choices' => [
                            ['value' => 'billing', 'description' => 'Payments and refunds.'],
                            ['value' => 'shipping'],
                        ],
                    ],
                    [
                        'type' => 'score',
                        'name' => 'severity',
                        'instructions' => '{"ask":"How severe?"}',
                        'levels' => [
                            ['label' => 'Cosmetic'],
                            ['label' => 'Workaround available', 'description' => 'Usable with extra steps.'],
                            ['label' => '{"impact":"total"}'],
                        ],
                    ],
                ],
            ];
    });
});

test('the raw decisions response is exposed on the classification response', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Is this urgent?'))->classify(provider: 'openai');

    expect($response->raw)->toBeInstanceOf(Response::class)
        ->and($response->raw->getJson()['model'])->toBe('gpt-6-luna');
});

test('structured state is sent as json text', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    Classification::of(['subject' => 'Refund', 'body' => 'Charged twice'])
        ->question('is_urgent', new Boolean('Is this urgent?'))
        ->classify(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['input'] === '{"subject":"Refund","body":"Charged twice"}');
});

test('image attachments are sent inline with the state as a user message', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');
    $png = file_get_contents(__DIR__ . '/../../../Fixtures/Images/red.png');

    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'upload-bytes');
    rewind($resource);
    $upload = new UploadedFile($resource, 12, UPLOAD_ERR_OK, 'upload.webp', 'image/webp');

    Classification::of('Inspect the product in this photo.', [
        Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg')->withProviderOptions(['custom' => 'value']),
        Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg'),
        $upload,
        Image::fromBase64(base64_encode($png)),
    ])
        ->question('visible_damage', new Boolean('Does the product have visible damage?'))
        ->classify(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['input'] === [[
        'role' => 'user',
        'content' => [
            ['type' => 'input_text', 'text' => 'Inspect the product in this photo.'],
            ['custom' => 'value', 'type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode('base64-bytes')],
            ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)],
            ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,' . base64_encode('upload-bytes')],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode($png)],
        ],
    ]]);
});

test('remote and stored images are downloaded and sent inline after their name labels', function (): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('remote-bytes', 200, ['Content-Type' => 'image/gif']),
        '*' => aiHttpResponse(fakeOpenAiDecisionsResponse()),
    ]);

    $png = file_get_contents(__DIR__ . '/../../../Fixtures/Images/red.png');
    LocalDisk::put('photos', 'product.png', $png);

    try {
        Classification::of('Inspect this.', [
            'front' => Image::fromUrl('https://example.com/product.gif'),
            'back' => Image::fromStorage('product.png', 'photos'),
        ])
            ->question('visible_damage', new Boolean('Damaged?'))
            ->classify(provider: 'openai');

        aiAssertHttpSent(fn(AiHttpRequest $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
            && array_slice(json_decode($request->body(), true)['input'][0]['content'], 1) === [
                ['type' => 'input_text', 'text' => 'front:'],
                ['type' => 'input_image', 'image_url' => 'data:image/gif;base64,' . base64_encode('remote-bytes')],
                ['type' => 'input_text', 'text' => 'back:'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . base64_encode($png)],
            ]);
    } finally {
        LocalDisk::cleanup('photos');
    }
});

test('attachments that are not supported images are rejected before any request', function (mixed $attachment, string $message): void {
    aiHttpFake([
        'example.com/*' => aiHttpResponse('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn(): ClassificationResponse => Classification::of('Inspect this.', [$attachment()])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'openai'))->toThrow(InvalidArgumentException::class, $message);

    expect(array_filter(
        aiHttpRecorded(),
        fn(array $pair): bool => str_contains($pair[0]->url(), 'api.openai.com'),
    ))->toBe([]);
})->with([
    'provider image' => [fn(): ProviderImage => Image::fromId('file_123'), 'OpenAI decisions only accept images with inline content; [Crustum\\Ai\\Files\\ProviderImage] given.'],
    'pdf upload' => [fn(): UploadedFile => (function (): UploadedFile {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'pdf-bytes');
        rewind($resource);

        return new UploadedFile($resource, 9, UPLOAD_ERR_OK, 'invoice.pdf', 'application/pdf');
    })(), '[application/pdf] given.'],
    'heic upload' => [fn(): UploadedFile => (function (): UploadedFile {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'heic-bytes');
        rewind($resource);

        return new UploadedFile($resource, 10, UPLOAD_ERR_OK, 'photo.heic', 'image/heic');
    })(), '[image/heic] given.'],
    'heic file' => [fn(): LocalImage => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/red.png', 'image/heic'), '[image/heic] given.'],
    'html served as a remote image' => [fn(): RemoteImage => Image::fromUrl('https://example.com/product.png'), '[text/html] given.'],
]);

test('decisions answers are matched to questions by name', function (): void {
    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    $response = Classification::of('text')
        ->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Department?', ['billing' => null, 'shipping' => null]),
            'severity' => new Score('Severity?', ['Cosmetic issue', 'Has workaround', 'Blocked']),
            'refused' => new Boolean('Refused?'),
        ])
        ->classify(provider: 'openai');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent']->probability)->toBe(0.92)
        ->and($response['department']->choice)->toBe('billing')
        ->and($response['department']->probabilities)->toBe(['billing' => 0.95, 'shipping' => 0.05])
        ->and($response['department']->confidence)->toBe(0.93)
        ->and($response['severity']->score)->toBe(1.1)
        ->and($response['severity']->probabilities)->toBe([0 => 0.1, 1 => 0.7, 2 => 0.2])
        ->and($response['severity']->label())->toBe('Has workaround')
        ->and($response['severity']->confidence)->toBe(0.55)
        ->and(isset($response['refused']))->toBeFalse()
        ->and($response->usage->inputTokens)->toBe(120)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-6-luna');
});

test('classification uses the configured classification model', function (): void {
    Configure::write('Ai.providers.openai.models.classification.default', 'gpt-6-luna-preview');

    aiHttpFake(['*' => aiHttpResponse(fakeOpenAiDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openai');

    aiAssertHttpSent(fn(AiHttpRequest $request): bool => json_decode($request->body(), true)['model'] === 'gpt-6-luna-preview');
});

test('attachments fail loudly instead of failing over to a provider that cannot classify them', function (): void {
    registerTextOnlyClassifier();

    aiHttpFake(['api.openai.com/*' => aiHttpResponse([], 503)]);

    expect(fn(): ClassificationResponse => Classification::of('Inspect this.', [Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: ['openai', 'text-only']))->toThrow(LogicException::class, 'Provider [text-only] does not support classification attachments.');

    aiAssertHttpSentCount(1);
});
