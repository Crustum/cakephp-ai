<?php
declare(strict_types=1);

use Crustum\Ai\Approvals\Decision;
use Crustum\Ai\Approvals\Decisions;
use Crustum\Ai\Contracts\AgentInput;
use Crustum\Ai\Files\Base64Image;
use Crustum\Ai\Messages\AssistantMessage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Prompts\AgentPrompt;
use Crustum\Ai\Prompts\QueuedAgentPrompt;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ConversationalAgent;
use Crustum\Broadcasting\Channel\Channel;

function agentInput(?UserMessage $message = null, ?Decisions $decisions = null): AgentInput
{
    return new class ($message, $decisions) implements AgentInput {
        public function __construct(private ?UserMessage $message, private ?Decisions $decisions)
        {
        }

        public function message(): ?UserMessage
        {
            return $this->message;
        }

        public function decisions(): ?Decisions
        {
            return $this->decisions;
        }
    };
}

test('a user message may be given as the prompt', function (): void {
    AssistantAgent::fake(['Hello there.']);

    $response = (new AssistantAgent())->prompt(
        new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')]),
    );

    expect($response->text)->toBe('Hello there.');

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
        && $prompt->attachments->count() === 1
        && $prompt->attachments->first() instanceof Base64Image);
});

test('a user message may be given as the prompt when streaming', function (): void {
    AssistantAgent::fake(['Hello there.']);

    iterator_to_array((new AssistantAgent())->stream(new UserMessage('Hello')));

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Hello');
});

test('agent input resolves to its user message', function (): void {
    AssistantAgent::fake(['Hello there.']);

    (new AssistantAgent())->prompt(agentInput(message: new UserMessage('From agent input')));

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'From agent input');
});

test('agent input resolves to its approval decisions before its user message', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent())->queue(agentInput(
        message: new UserMessage('Ignored'),
        decisions: Decision::approveAll(),
    ));

    AssistantAgent::assertQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
});

test('empty agent input is rejected', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent())->prompt(agentInput());
})->throws(InvalidArgumentException::class, 'The agent input contains no user message or approval decisions.');

test('ad-hoc message history is sent ahead of the prompt', function (): void {
    AssistantAgent::fake(['How can I help?']);

    $history = [
        new UserMessage('Hello'),
        new AssistantMessage('Hi! How can I help you today?'),
    ];

    (new AssistantAgent())->withMessages($history)->prompt('What is CakePHP?');

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->messages === $history
        && $prompt->prompt === 'What is CakePHP?');
});

test('ad-hoc message history may be given as raw arrays', function (): void {
    AssistantAgent::fake(['How can I help?']);

    (new AssistantAgent())
        ->withMessages([['role' => 'user', 'content' => 'Hello']])
        ->prompt('What is CakePHP?');

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => count($prompt->messages) === 1
        && $prompt->messages[0]->content === 'Hello');
});

test('ad-hoc message history may be given as UI message arrays', function (): void {
    AssistantAgent::fake(['How can I help?']);

    (new AssistantAgent())
        ->withMessages([['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Hello']]]])
        ->prompt('What is CakePHP?');

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => count($prompt->messages) === 1
        && $prompt->messages[0]->content === 'Hello');
});

test('ad-hoc message history may not be combined with a conversational agent', function (): void {
    (new ConversationalAgent())->withMessages([new UserMessage('Hello')]);
})->throws(LogicException::class);

test('ad-hoc message history does not leak into a later prompt', function (): void {
    AssistantAgent::fake(['First.', 'Second.']);

    $agent = new AssistantAgent();

    $agent->withMessages([new UserMessage('Hello')])->prompt('First prompt');
    $agent->prompt('Second prompt');

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt'
        && count($prompt->messages) === 1);

    AssistantAgent::assertPrompted(fn(AgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt'
        && $prompt->messages === null);
});

test('agent input resolves to its approval decisions when broadcasting on the queue', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent())->broadcastOnQueue(agentInput(
        message: new UserMessage('Ignored'),
        decisions: Decision::approveAll(),
    ), new Channel('approvals'));

    AssistantAgent::assertQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());
});

test('a user message keeps its attachments when broadcasting on the queue', function (): void {
    AssistantAgent::fake();

    (new AssistantAgent())->broadcastOnQueue(
        new UserMessage('Hello', [new Base64Image(base64_encode('image'), 'image/png')]),
        new Channel('approvals'),
    );

    AssistantAgent::assertQueued(fn(QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Hello'
        && count($prompt->attachments) === 1
        && collection($prompt->attachments)->first() instanceof Base64Image);
});
