<?php
declare(strict_types=1);

use Crustum\Ai\Classification;
use Crustum\Ai\Classification\Boolean;
use Crustum\Ai\Classification\Choice;
use Crustum\Ai\Classification\Score;
use Crustum\Ai\Event\Classified;
use Crustum\Ai\Event\Classifying;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\Skips\ApiKey;

test('states can be classified', function (string $provider, string $apiKey): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([Classifying::class, Classified::class]);

    $response = Classification::of("I've been trying to connect my Stripe account for 3 days and it keeps failing. I'm losing sales. Please help ASAP.")
        ->questions([
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should handle this?', [
                'billing' => 'Payments, invoicing, refunds',
                'technical' => 'Bugs, outages, integrations',
                'sales' => 'Pricing, plans, upgrades',
            ]),
            'frustration' => new Score('How frustrated is the customer?', [
                'Calm, stating facts', 'Frustrated but civil', 'Very angry',
            ]),
        ])->classify(provider: $provider);

    expect($response['is_urgent']->isTrue())->toBeTrue()
        ->and($response['department']->choice)->toBe('technical')
        ->and(round(array_sum($response['department']->probabilities), 1))->toBe(1.0)
        ->and($response['frustration']->score)->toBeGreaterThan(0.5)
        ->and($response->usage->inputTokens)->toBeGreaterThan(0)
        ->and($response->meta->provider)->toBe($provider);

    $recorder->assertDispatched(Classifying::class);
    $recorder->assertDispatched(Classified::class);
})->with('classification-providers');
