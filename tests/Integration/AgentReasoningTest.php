<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\Storage\DatabaseConversationStore;
use Crustum\Ai\Test\Fixtures\Agents\RememberingToolAgent;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Database\ConversationSchema;
use Crustum\Ai\Test\Support\Database\ConversationTable;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Trait\PromptableTrait;

test('models can replay conversation history with tool calls', function (string $provider, string $apiKey, string $model, bool $isReasoning): void {
    ApiKey::required($apiKey);

    $tool = new FixedNumberGenerator();
    $instructions = 'For every request, call the FixedNumberGenerator tool before answering. Answer with one short sentence.';

    $makeAgent = fn(iterable $messages = []): object => new class ($instructions, $messages, [$tool], $isReasoning) implements Agent, Conversational, HasProviderOptions, HasTools
    {
        use PromptableTrait;

        public function __construct(
            public string $instructions,
            public iterable $messages,
            public iterable $tools,
            public bool $isReasoning,
        ) {
        }

        public function instructions(): string
        {
            return $this->instructions;
        }

        public function messages(): iterable
        {
            return $this->messages;
        }

        public function tools(): iterable
        {
            return $this->tools;
        }

        public function providerOptions(Lab|string $provider): array
        {
            $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

            if (!$this->isReasoning) {
                return [];
            }

            return match ($provider) {
                Lab::OpenAI, Lab::Azure => [
                    'reasoning' => ['effort' => 'high'],
                ],
                default => [],
            };
        }
    };

    $firstPrompt = 'What fixed number is available?';
    $firstResponse = $makeAgent()->prompt(
        $firstPrompt,
        provider: $provider,
        model: $model,
    );

    expect($firstResponse->toolCalls)->toHaveCount(1)
        ->and($firstResponse->text)->not->toBeEmpty();

    $secondResponse = $makeAgent([
        new UserMessage($firstPrompt),
        ...$firstResponse->messages->toList(),
    ])->prompt(
        'Thanks. Confirm the fixed number again.',
        provider: $provider,
        model: $model,
    );

    expect($secondResponse->text)->toContain('72019');
})->with('tool-replay-providers');

beforeEach(function (): void {
    Configure::write('Ai.conversations.connection', 'test');
    Configure::write('Ai.conversations.tables.conversations', 'agent_conversations');
    Configure::write('Ai.conversations.tables.messages', 'agent_conversation_messages');
    Configure::write('Ai.conversationStore', DatabaseConversationStore::class);
    ConversationSchema::create('test');
});

test('reasoning conversation roundtrips through database storage', function (): void {
    ApiKey::required('OPENAI_API_KEY');

    $user = (object)['id' => '00000000-0000-0000-0000-000000000001'];
    $firstPrompt = 'What fixed number is available?';

    $agent = (new RememberingToolAgent(['reasoning' => ['effort' => 'high']]))
        ->forUser($user);

    $firstResponse = $agent->prompt(
        $firstPrompt,
        provider: 'openai',
        model: 'gpt-6-luna',
    );

    expect($firstResponse->toolCalls)->toHaveCount(1)
        ->and($firstResponse->text)->not->toBeEmpty();

    $conversationId = $agent->currentConversation();

    expect($conversationId)->not->toBeNull();

    $record = ConversationTable::first('agent_conversation_messages', [
        'conversation_id' => $conversationId,
        'role' => 'assistant',
    ]);

    expect($record)->not->toBeNull();

    $step = json_decode((string)$record->steps, true)[0];

    expect($step['tool_calls'])->toHaveCount(1)
        ->and($step['tool_calls'][0])->not->toHaveKey('reasoning_id')
        ->and(collect($step['replay_blocks'])->firstMatch(['type' => 'reasoning']))->not->toBeNull();

    $secondResponse = (new RememberingToolAgent())
        ->continue($conversationId, $user)
        ->prompt(
            'Confirm the fixed number again.',
            provider: 'openai',
            model: 'gpt-4.1',
        );

    expect($secondResponse->text)->toContain('72019');
});
