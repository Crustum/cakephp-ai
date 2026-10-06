<?php
declare(strict_types=1);

use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;

test('prompt reads thought steps off the response', function (): void {
    aiHttpFake(['*' => $this->fakeThinkingResponse([
        $this->thoughtStep('Let me think...'),
        $this->modelOutput('Hello'),
    ])]);

    expect((new AssistantAgent())->prompt('Hi', provider: 'gemini')->reasoning)->toBe('Let me think...');
});

test('prompt separates thought runs interrupted by an answer with a blank line', function (): void {
    aiHttpFake(['*' => $this->fakeThinkingResponse([
        $this->thoughtStep('First.'),
        $this->modelOutput('Partial answer. '),
        $this->thoughtStep('Second.'),
        $this->modelOutput('Rest of the answer.'),
    ])]);

    $response = (new AssistantAgent())->prompt('Hi', provider: 'gemini');

    expect($response->reasoning)->toBe("First.\n\nSecond.")
        ->and($response->text)->toBe('Partial answer. Rest of the answer.');
});
