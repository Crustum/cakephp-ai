<?php
declare(strict_types=1);

use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Files\Document;

test('array provider options resolve for the given provider', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(['purpose' => 'fine-tune']);

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'fine-tune']);
});

test('closure provider options resolve per provider', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(fn(Lab $provider): array => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants'])
        ->and($document->providerOptions(Lab::Anthropic))->toBe([]);
});

test('closure provider options survive php serialization', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(fn(Lab $provider): array => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    $restored = unserialize(serialize($document));

    expect($restored->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants']);
});
