<?php
declare(strict_types=1);

use Cake\Event\EventManager;
use Crustum\Ai\Contracts\Agent;
use Crustum\Ai\Contracts\Providers\TextProvider;
use Crustum\Ai\Contracts\Tool;
use Crustum\Ai\Event\InvokingTool;
use Crustum\Ai\Event\ToolFailed;
use Crustum\Ai\Event\ToolInvoked;
use Crustum\Ai\Gateway\RunContext;
use Crustum\Ai\Gateway\Trait\InvokesToolsTrait;
use Crustum\Ai\Tools\Request;
use Crustum\JsonSchema\Contracts\JsonSchema;

function toolInvokingGateway(): object
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

function stubTool(string $name, Closure $handler): Tool
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

        /**
         * @return array<string, Type>
         */
        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

function stubRunContext(EventManager $events, string $invocationId = 'inv_1'): RunContext
{
    return new RunContext(
        $invocationId,
        Mockery::mock(Agent::class),
        Mockery::mock(TextProvider::class),
        'stub-model',
        $events,
    );
}

test('each tool invocation reports against the context it was given', function (): void {
    $seen = [];

    $context = function (string $label) use (&$seen): RunContext {
        $events = new EventManager();

        $events->on('Ai.invokingTool', function (InvokingTool $event) use (&$seen, $label): void {
            $seen[] = $label . ' invoking ' . $event->tool->description();
        });

        $events->on('Ai.toolInvoked', function (ToolInvoked $event) use (&$seen, $label): void {
            $seen[] = $label . ' invoked ' . $event->tool->description() . ':' . $event->result;
        });

        return stubRunContext($events);
    };

    $gateway = toolInvokingGateway();

    $nestedTool = stubTool('nested', fn(): string => 'nested result');

    $delegatingTool = stubTool('delegating', function () use ($gateway, $nestedTool, $context): string {
        $gateway->invoke($nestedTool, context: $context('sub'));

        return 'delegated result';
    });

    $siblingTool = stubTool('sibling', fn(): string => 'sibling result');

    $gateway->invoke($delegatingTool, context: $context('parent'));
    $gateway->invoke($siblingTool, context: $context('parent'));

    expect($seen)->toBe([
        'parent invoking delegating',
        'sub invoking nested',
        'sub invoked nested:nested result',
        'parent invoked delegating:delegated result',
        'parent invoking sibling',
        'parent invoked sibling:sibling result',
    ]);
});

test('the invoking and invoked events share a tool invocation id', function (): void {
    $ids = [];

    $events = new EventManager();
    $events->on('Ai.invokingTool', function (InvokingTool $event) use (&$ids): void {
        $ids[] = $event->toolInvocationId;
    });
    $events->on('Ai.toolInvoked', function (ToolInvoked $event) use (&$ids): void {
        $ids[] = $event->toolInvocationId;
    });

    toolInvokingGateway()->invoke(stubTool('paired', fn(): string => 'result'), context: stubRunContext($events));

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toBe($ids[1]);
});

test('only the tool handler itself can fail a tool invocation', function (): void {
    $invoked = 0;
    $failed = 0;

    $events = new EventManager();
    $events->on('Ai.toolInvoked', function () use (&$invoked): void {
        $invoked++;
    });
    $events->on('Ai.toolFailed', function () use (&$failed): void {
        $failed++;
    });

    $tool = stubTool('unstringable', fn(): Stringable => new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('Stringify exploded.');
        }
    });

    expect(fn(): string => toolInvokingGateway()->invoke($tool, context: stubRunContext($events)))
        ->toThrow(RuntimeException::class, 'Stringify exploded.');

    expect($invoked)->toBe(1)
        ->and($failed)->toBe(0);
});

test('a listener that throws is not reported as a tool failure', function (): void {
    $failed = 0;

    $events = new EventManager();
    $events->on('Ai.toolInvoked', function (): void {
        throw new RuntimeException('Listener exploded.');
    });
    $events->on('Ai.toolFailed', function () use (&$failed): void {
        $failed++;
    });

    $tool = stubTool('fine', fn(): string => 'result');

    expect(fn(): string => toolInvokingGateway()->invoke($tool, context: stubRunContext($events)))
        ->toThrow(RuntimeException::class, 'Listener exploded.');

    expect($failed)->toBe(0);
});

test('a failing tool handler is reported as a tool failure and rethrown', function (): void {
    $failure = null;

    $events = new EventManager();
    $events->on('Ai.toolFailed', function (ToolFailed $event) use (&$failure): void {
        $failure = $event;
    });

    $tool = stubTool('exploding', function (): string {
        throw new RuntimeException('Handler exploded.');
    });

    expect(fn(): string => toolInvokingGateway()->invoke($tool, ['a' => 1], stubRunContext($events)))
        ->toThrow(RuntimeException::class, 'Handler exploded.');

    expect($failure)->toBeInstanceOf(ToolFailed::class)
        ->and($failure->invocationId)->toBe('inv_1')
        ->and($failure->arguments)->toBe(['a' => 1])
        ->and($failure->exception->getMessage())->toBe('Handler exploded.');
});

test('tool events carry the wall time spent in the handler', function (): void {
    $invoked = null;

    $events = new EventManager();
    $events->on('Ai.toolInvoked', function (ToolInvoked $event) use (&$invoked): void {
        $invoked = $event;
    });

    $tool = stubTool('slow', function (): string {
        usleep(2000);

        return 'result';
    });

    toolInvokingGateway()->invoke($tool, context: stubRunContext($events));

    expect($invoked->time)->toBeFloat()->toBeGreaterThan(1.0);
});

test('a tool invocation without a run context is silent', function (): void {
    $gateway = new class
    {
        use InvokesToolsTrait;

        public function invoke(Tool $tool): string
        {
            return $this->executeTool($tool, []);
        }
    };

    expect($gateway->invoke(stubTool('unobserved', fn(): string => 'result')))->toBe('result');
});
