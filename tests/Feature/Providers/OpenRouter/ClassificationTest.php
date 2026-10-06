<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Responses\Data\ChoiceAnswer;
use Crustum\Ai\Responses\Data\ScoreAnswer;
use Crustum\Ai\Test\Support\Http\AiHttpRequest;

beforeEach(function (): void {
    Configure::write('Ai.providers.openrouter.key', 'test-key');
});

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
