<?php
declare(strict_types=1);

use Crustum\Ai\Classification;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Prompts\ClassificationPrompt;
use Crustum\Ai\Responses\Data\BooleanAnswer;
use Crustum\Ai\Text;

test('decision can be made from text stringable', function (): void {
    Classification::fake(fn(ClassificationPrompt $prompt): array => [
        'decision' => new BooleanAnswer($prompt->contains('FREE MONEY') ? 0.95 : 0.05),
    ]);

    expect(Text::of('FREE MONEY NOW')->decide('Is this spam?'))->toBeTrue()
        ->and(Text::of('Lunch at noon?')->decide('Is this spam?'))->toBeFalse();

    Classification::assertClassified(fn(ClassificationPrompt $prompt): bool => $prompt->asks('decision')
        && $prompt->contains('FREE MONEY'));
});

test('decision honors the given threshold', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.7)]]);

    expect(Text::of('Maybe spam.')->decide('Is this spam?', threshold: 0.9))->toBeFalse();
});

test('decision macro passes through criteria and options', function (): void {
    Classification::fake();

    Text::decide(
        'Some text.',
        'Is this spam?',
        criteria: ['true' => 'Unsolicited bulk mail.'],
        provider: Lab::TypeSafe,
        model: 'custom-model',
        timeout: 45,
    );

    Classification::assertClassified(fn(ClassificationPrompt $prompt): bool => $prompt->contains('Some text.')
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45
        && $prompt->questions['decision']->criteria === ['true' => 'Unsolicited bulk mail.']);
});
