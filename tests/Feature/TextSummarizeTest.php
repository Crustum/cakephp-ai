<?php
declare(strict_types=1);

use Crustum\Ai\Agents\SummarizeAgent;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Text;

test('summary can be generated from text stringable', function (): void {
    SummarizeAgent::fake(['A short summary.']);

    $summary = Text::of('A very long piece of text that needs summarizing.')->summarize();

    expect($summary)->toBe('A short summary.');

    SummarizeAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
        && str_contains($prompt->agent->instructions(), '3 sentences'));
});

test('summary honors requested sentence count', function (): void {
    SummarizeAgent::fake();

    Text::of('Some text.')->summarize(sentences: 1);

    SummarizeAgent::assertPrompted(fn(AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), '1 sentence')
        && !str_contains($prompt->agent->instructions(), '1 sentences'));
});

test('summary uses the cheapest model by default', function (): void {
    SummarizeAgent::fake();

    Text::of('Some text.')->summarize();

    SummarizeAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->model === $prompt->provider->cheapestTextModel());
});

test('summary can be generated from static Text helper', function (): void {
    SummarizeAgent::fake(['A short summary.']);

    $summary = Text::summarize('A very long piece of text that needs summarizing.', sentences: 4);

    expect($summary)->toBe('A short summary.');

    SummarizeAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
        && str_contains($prompt->agent->instructions(), '4 sentences'));
});

test('summary helper passes through options', function (): void {
    SummarizeAgent::fake();

    Text::of('Some text.')->summarize(
        sentences: 4,
        provider: Lab::OpenAI,
        model: 'custom-model',
        timeout: 45,
    );

    SummarizeAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Some text.'
        && $prompt->provider instanceof OpenAiProvider
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});
