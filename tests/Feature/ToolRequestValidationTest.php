<?php
declare(strict_types=1);

use Cake\Event\EventManager;
use Cake\Validation\Validator;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Exception\ValidationException;
use Crustum\Ai\Gateway\RunContext;
use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;
use JMac\Testing\Double;

function validationToolInvokingGateway(): object
{
    return new class
    {
        use InvokesToolsTrait;

        public function invoke(Tool $tool, array $arguments = [], ?RunContext $context = null): string
        {
            return $this->executeTool($tool, $arguments, null, $context);
        }
    };
}

function validationStubTool(string $name, Closure $handler): Tool
{
    return new class ($name, $handler) implements Tool
    {
        public function __construct(
            protected string $name,
            protected Closure $handler,
        ) {
        }

        public function description(): string
        {
            return $this->name;
        }

        public function handle(Request $request): Stringable|string
        {
            return call_user_func($this->handler, $request);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

function validationStubRunContext(EventManager $events, string $invocationId = 'inv_1'): RunContext
{
    return new RunContext(
        $invocationId,
        Double::for(Agent::class),
        Double::for(TextProvider::class),
        'stub-model',
        $events,
    );
}

test('tool request arguments can be validated', function (): void {
    $validated = (new Request(['city' => 'Lisbon', 'extra' => 'ignored']))->validate([
        'city' => 'required|string',
    ]);

    expect($validated)->toBe(['city' => 'Lisbon']);
});

test('invalid tool request arguments throw a validation exception', function (): void {
    (new Request(['days' => 'tomorrow']))->validate(['days' => 'required|integer']);
})->throws(ValidationException::class);

test('tool request arguments can be validated with a cake validator', function (): void {
    $validator = (new Validator())
        ->requirePresence('city', true)
        ->notEmptyString('city')
        ->integer('days')
        ->greaterThan('days', 0)
        ->lessThanOrEqual('days', 7);

    $validated = (new Request(['city' => 'Lisbon', 'days' => 3, 'extra' => 'ignored']))->validate($validator);

    expect($validated)->toBe(['city' => 'Lisbon', 'days' => 3]);
});

test('a cake validator failure throws a validation exception', function (): void {
    $validator = (new Validator())
        ->requirePresence('city', true)
        ->notEmptyString('city');

    (new Request([]))->validate($validator);
})->throws(ValidationException::class);

test('a validation failure is returned to the model as the tool result', function (): void {
    $invoked = null;
    $failed = 0;

    $events = new EventManager();
    $events->on('Ai.toolInvoked', function (ToolInvoked $event) use (&$invoked): void {
        $invoked = $event;
    });
    $events->on('Ai.toolFailed', function () use (&$failed): void {
        $failed++;
    });

    $gateway = validationToolInvokingGateway();

    $tool = validationStubTool('validating', function (Request $request): string {
        $request->validate(['city' => 'required|string']);

        return 'never reached';
    });

    $context = validationStubRunContext($events);

    $result = $gateway->invoke($tool, [], $context);

    expect($result)->toBeString()->not->toBeEmpty()
        ->and($invoked->result)->toBe($result)
        ->and($failed)->toBe(0);
});
