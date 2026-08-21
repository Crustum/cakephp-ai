<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Ai;

beforeEach(function (): void {
    Configure::write('Ai.providers.eleven', [

        ...(array)Configure::read('Ai.providers.eleven'),
        'key' => 'test-key',
    ]);
});

test('audio gateway is memoized across calls', function (): void {
    $provider = Ai::getManager()->audioProvider('eleven');

    expect($provider->audioGateway())->toBe($provider->audioGateway());
});

test('transcription gateway is memoized across calls', function (): void {
    $provider = Ai::getManager()->transcriptionProvider('eleven');

    expect($provider->transcriptionGateway())->toBe($provider->transcriptionGateway());
});
