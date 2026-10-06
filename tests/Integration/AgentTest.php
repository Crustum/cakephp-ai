<?php
declare(strict_types=1);

use Cake\Validation\Validation;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Conversational;
use Crustum\Ai\Contracts\HasProviderOptions;
use Crustum\Ai\Contracts\HasTools;
use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Event\AgentPrompted;
use Crustum\Ai\Event\AgentStreamed;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\PromptingAgent;
use Crustum\Ai\Event\StreamingAgent;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Files;
use Crustum\Ai\Files\LocalImage;
use Crustum\Ai\Messages\UserMessage;
use Crustum\Ai\PendingStep;
use Crustum\Ai\Responses\StreamedAgentResponse;
use Crustum\Ai\Skills\Skill;
use Crustum\Ai\Streaming\Event\TextDelta;
use Crustum\Ai\Support\ToolChoice;
use Crustum\Ai\Test\Fixtures\Agents\AssistantAgent;
use Crustum\Ai\Test\Fixtures\Agents\ConversationalAgent;
use Crustum\Ai\Test\Fixtures\Agents\StructuredAgent;
use Crustum\Ai\Test\Fixtures\Agents\ToolUsingAgent;
use Crustum\Ai\Test\Fixtures\Tools\FixedNumberGenerator;
use Crustum\Ai\Test\Support\Event\EventRecorder;
use Crustum\Ai\Test\Support\IntegrationPrompts;
use Crustum\Ai\Test\Support\Skips\ApiKey;
use Crustum\Ai\Test\Support\Storage\LocalDisk;
use Crustum\Ai\Tools\LoadSkill;
use Crustum\Ai\Trait\PromptableTrait;
use Crustum\JsonSchema\Contracts\JsonSchema;

test('agents can get a simple text response', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([PromptingAgent::class, AgentPrompted::class]);

    $agent = new AssistantAgent();

    $response = $agent->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue()
        ->and(Validation::uuid($response->invocationId))->toBeTrue()
        ->and($response->messages->count())->toBeGreaterThan(0)
        ->and($response->meta->provider)->toEqual($provider)
        ->and($response->meta->model)->toBeString()->not->toBeEmpty()
        ->and($response->steps->count())->toBeGreaterThan(0);

    $recorder->assertDispatched(PromptingAgent::class);
    $recorder->assertDispatched(AgentPrompted::class);
})->with('agent-providers');

test('agents can load a skill from a catalog longer than 1024 characters', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $skills = collection(range(1, 4))->map(fn(int $index): Skill => new Skill(
        name: "filler-{$index}",
        description: str_repeat('Use when the user asks about an unrelated filler topic. ', 9),
        instructions: 'Reply with the word filler.',
    ))->appendItem(new Skill(
        name: 'secret-word',
        description: 'Use when the user asks for the secret word.',
        instructions: 'The secret word is pomegranate.',
    ))->toList();

    $response = agent(tools: [new LoadSkill($skills)])->prompt(
        'Load the secret-word skill and tell me the secret word.',
        provider: $provider,
        model: $model,
    );

    expect(strtolower($response->text))->toContain('pomegranate');
})->with('agent-providers');

test('ad hoc agents can be prompted', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent()->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue();
})->with('agent-providers');

test('agents can stream a response', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([StreamingAgent::class, AgentStreamed::class]);

    $agent = new AssistantAgent();

    $response = $agent->stream(
        IntegrationPrompts::question('knowledge'),
        provider: $provider,
        model: $model,
    )->then(function (StreamedAgentResponse $response): void {
        $_SERVER['__testing.response'] = $response;
    })->then(function (): void {
        $_SERVER['__testing.invoked'] = true;
    });

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect(collection($events)
        ->filter(fn($event): bool => $event instanceof TextDelta)
        ->count())->toBeGreaterThan(0)
        ->and(IntegrationPrompts::matches('knowledge', (string)$response->text))->toBeTrue()
        ->and($_SERVER['__testing.response']->events)->toHaveCount(count($events))
        ->and($_SERVER['__testing.invoked'])->toBeTrue();

    $recorder->assertDispatched(StreamingAgent::class);
    $recorder->assertDispatched(AgentStreamed::class);

    unset($_SERVER['__testing.response']);
    unset($_SERVER['__testing.invoked']);
})->with('agent-providers');

test('agents can queue a response', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $agent = new AssistantAgent();

    $response = $agent->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue();
})->with('agent-providers');

test('ad hoc agents can queue a response', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent()->prompt(
        IntegrationPrompts::question('knowledge'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('knowledge', $response->text))->toBeTrue();
})->with('agent-providers');

test('ad hoc structured agents can queue a response', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent(
        schema: fn($schema): array => [
            'symbol' => $schema->string()->required(),
        ],
    )->prompt(
        IntegrationPrompts::question('chemical_symbol'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('chemical_symbol', (string)$response['symbol']))->toBeTrue();
})->with('agent-providers');

test('agents can have conversation state', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $agent = new ConversationalAgent();

    $response = $agent->prompt(
        IntegrationPrompts::question('conversation_name'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('conversation_name', $response->text))->toBeTrue();
})->with('agent-providers');

test('agents can have structured output', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $agent = new StructuredAgent();

    $response = $agent->prompt(
        IntegrationPrompts::question('chemical_symbol'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('chemical_symbol', (string)$response['symbol']))->toBeTrue()
        ->and($response->steps->count())->toBeGreaterThan(0);
})->with('agent-providers');

test('ad hoc agents can have structured output', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $response = agent(
        schema: fn(JsonSchema $schema): array => [
            'symbol' => $schema->string()->required(),
        ],
    )->prompt(
        IntegrationPrompts::question('chemical_symbol'),
        provider: $provider,
        model: $model,
    );

    expect(IntegrationPrompts::matches('chemical_symbol', (string)$response['symbol']))->toBeTrue();
})->with('agent-providers');

test('agents can use tools', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $recorder = EventRecorder::start([InvokingTool::class, ToolInvoked::class]);

    $agent = new ToolUsingAgent();

    $response = $agent->prompt(
        'Can I have a random number between 1 and 1000?',
        provider: $provider,
        model: $model,
    );

    expect($response['number'])->toBeBetween(1, 1000)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(1);

    $recorder->assertDispatched(InvokingTool::class);

    $recorder->assertMatches(ToolInvoked::class, fn($event): bool => !is_null($event->toolInvocationId));

    $agent = new ToolUsingAgent(fixed: true);

    $response = $agent->prompt(
        'Can I have a random number?',
        provider: $provider,
        model: $model,
    );

    expect($response['number'])->toBe(72019);
})->with('agent-providers');

test('agent middleware can steer each generation step', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $steps = [];

    $response = (new AssistantAgent())
        ->withTools([new FixedNumberGenerator()])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$steps) {
            $steps[] = $step->number;

            return $next($step->withToolChoice($step->isFirstStep() ? ToolChoice::tool('FixedNumberGenerator') : ToolChoice::NONE));
        }])
        ->prompt('Fetch a number with the tool, then reply with it.', provider: $provider, model: $model);

    expect($steps)->toBe([0, 1])
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('72019')
        ->and($response->text)->not->toBe('');
})->with('agent-providers');

test('agents can replay empty tool arguments', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    $tool = new FixedNumberGenerator();
    $instructions = 'For every request, call the FixedNumberGenerator tool before answering. Answer with one short sentence.';
    $firstPrompt = 'What fixed number is available?';
    $makeAgent = fn(iterable $messages = []): object => new class ($instructions, $messages, [$tool]) implements Agent, Conversational, HasProviderOptions, HasTools
    {
        use PromptableTrait;

        public function __construct(
            public string $instructions,
            public iterable $messages,
            public iterable $tools,
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

            return $provider === Lab::DeepSeek
                ? ['thinking' => ['type' => 'disabled']]
                : [];
        }
    };

    $firstResponse = $makeAgent()->prompt(
        $firstPrompt,
        provider: $provider,
        model: $model,
    );

    expect($firstResponse->toolCalls)->toHaveCount(1)
        ->and($firstResponse->toolCalls->first()->arguments)->toBe([]);

    $secondResponse = $makeAgent([
        new UserMessage($firstPrompt),
        ...$firstResponse->messages->toList(),
    ])->prompt(
        'Thanks. Confirm the fixed number again.',
        provider: $provider,
        model: $model,
    );

    expect($secondResponse->text)->not->toBeEmpty()
        ->and($secondResponse->toolCalls)->toHaveCount(1)
        ->and($secondResponse->toolCalls->first()->arguments)->toBe([]);
})->with('agent-providers');

test('agents can analyze text document attachments', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    LocalDisk::put('docs', 'document-one.txt', 'The capital of France is Paris.');
    LocalDisk::put('docs', 'document-two.txt', 'The capital of Japan is Tokyo.');
    LocalDisk::put('docs', 'document-three.txt', 'The capital of Australia is Canberra.');

    try {
        $record = ['name' => 'Larry Masters', 'role' => 'creator of CakePHP'];

        $response = agent('Answer using only the attached documents.')->prompt(
            'List the three capital cities mentioned across the attached documents and name the person in the JSON record.',
            [
                Files\Document::fromStorage('document-one.txt', 'docs'),
                Files\Document::fromStorage('document-two.txt', 'docs'),
                Files\Document::fromStorage('document-three.txt', 'docs'),
                Files\Document::fromString(json_encode($record), 'text/plain'),
            ],
            provider: $provider,
            model: $model,
        );

        expect($response->text)->toContain('Paris')
            ->and($response->text)->toContain('Tokyo')
            ->and($response->text)->toContain('Canberra')
            ->and($response->text)->toContain('Larry');
    } finally {
        LocalDisk::cleanup('docs');
    }
})->with('agent-document-providers');

test('agents can analyze local image attachments with a detected mime type', function (string $provider, string $apiKey, string $model, string $file, string $color): void {
    ApiKey::required($apiKey);

    $response = agent('Answer briefly.')->prompt(
        'What color is the background of this image? Answer with just one word.',
        [new LocalImage(__DIR__ . '/../Fixtures/Images/' . $file)],
        provider: $provider,
        model: $model,
    );

    expect(strtolower($response->text))->toContain($color);
})->with('agent-image-providers')->with([
    'png' => ['red.png', 'red'],
    'jpeg' => ['blue.jpg', 'blue'],
]);

test('agent tool exception handling is not magical', function (string $provider, string $apiKey, string $model): void {
    ApiKey::required($apiKey);

    EventRecorder::start([InvokingTool::class, ToolInvoked::class]);

    $agent = new ToolUsingAgent(toolThrowsException: true);

    $caught = false;

    try {
        $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $provider,
            model: $model,
        );
    } catch (Exception $exception) {
        $caught = true;

        expect($exception)->toBeInstanceOf(Exception::class)
            ->and($exception->getMessage())->toEqual('Forced to throw exception.');
    }

    expect($caught)->toBeTrue();
})->with('agent-providers');

test('agents surface the reasoning a prompted turn produced', function (string $provider, string $apiKey, string $model, array $options): void {
    ApiKey::required($apiKey);

    $response = reasoningAgent($options)->prompt(
        reasoningPrompt(),
        provider: $provider,
        model: $model,
    );

    expect($response->reasoning)->not->toBe('')
        ->and($response->text)->toContain('10')
        ->and($response->steps->last()->reasoning)->not->toBe('');
})->with('reasoning-providers');

test('agents surface the same reasoning whether prompted or streamed', function (string $provider, string $apiKey, string $model, array $options): void {
    ApiKey::required($apiKey);

    $streamed = reasoningAgent($options)->stream(reasoningPrompt(), provider: $provider, model: $model);

    iterator_to_array($streamed);

    expect($streamed->reasoning)->not->toBe('')
        ->and($streamed->text)->toContain('10');
})->with('reasoning-providers');

function reasoningPrompt(): string
{
    return 'Box A has twice as many balls as box B. Box C has five fewer balls than box A. '
        . 'The three boxes hold 45 balls in total. How many balls are in box B? Answer with just the number.';
}

function reasoningAgent(array $options): object
{
    return new class ($options) implements Agent, HasProviderOptions
    {
        use PromptableTrait;

        public function __construct(public array $options)
        {
        }

        public function instructions(): string
        {
            return 'You are a helpful assistant that responds extremely concisely to all queries.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return $this->options;
        }
    };
}
