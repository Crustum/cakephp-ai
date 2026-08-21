<?php
declare(strict_types=1);

use Crustum\Ai\Test\Support\IntegrationPrompts;

test('integration prompts load knowledge question and expected substrings', function (): void {
    $definition = IntegrationPrompts::get('knowledge');

    expect($definition['id'])->toBe('knowledge')
        ->and(IntegrationPrompts::question('knowledge'))->not->toBeEmpty()
        ->and(IntegrationPrompts::expected('knowledge'))->not->toBeEmpty()
        ->and(IntegrationPrompts::matches('knowledge', IntegrationPrompts::expected('knowledge')[0]))->toBeTrue();
});

test('integration prompts support equals_ci matching for structured values', function (): void {
    expect(IntegrationPrompts::matches('chemical_symbol', 'Ag'))->toBeTrue()
        ->and(IntegrationPrompts::matches('chemical_symbol', 'ag'))->toBeTrue()
        ->and(IntegrationPrompts::matches('chemical_symbol', 'Au'))->toBeFalse();
});

test('integration prompts render research template with question placeholder', function (): void {
    $prompt = IntegrationPrompts::prompt('research');
    $question = IntegrationPrompts::question('research');

    expect($prompt)->toContain($question)
        ->and($prompt)->toContain('research_agent');
});

test('integration prompts expose conversation context', function (): void {
    expect(IntegrationPrompts::context('conversation_name'))->not->toBeEmpty()
        ->and(IntegrationPrompts::question('conversation_name'))->not->toBeEmpty();
});
