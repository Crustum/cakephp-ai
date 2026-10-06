<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Feature\Providers\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Cake\Event\EventManager;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Bedrock\BedrockRerankingGateway;
use Crustum\Ai\Gateway\Bedrock\BedrockTextGateway;
use Crustum\Ai\Gateway\TextGenerationLoop;
use Crustum\Ai\Gateway\TextGenerationOptions;
use Crustum\Ai\Providers\BedrockProvider;
use GuzzleHttp\Psr7\Utils;

trait BedrockHelpersTrait
{
    protected function fakeBedrockConverse(array $result): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result($result)]));
    }

    protected function fakeBedrockInvoke(array $body): BedrockRuntimeClient
    {
        return $this->bedrockClient($this->bedrockInvokeMock($body));
    }

    protected function bedrockInvokeMock(array|string $body): MockHandler
    {
        return new MockHandler([new Result([
            'body' => Utils::streamFor(is_string($body) ? $body : json_encode($body)),
        ])]);
    }

    protected function fakeBedrockInvokeWithHeaders(array $body, array $headers): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([new Result([
            'body' => Utils::streamFor(json_encode($body)),
            '@metadata' => ['headers' => $headers],
        ])]));
    }

    protected function fakeBedrockStream(array $events): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler([
            new Result(['stream' => $events]),
        ]));
    }

    protected function fakeBedrockStreamSequence(array $eventLists): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn(array $events): Result => new Result(['stream' => $events]),
            $eventLists,
        )));
    }

    protected function fakeBedrockConverseSequence(array $results): BedrockRuntimeClient
    {
        return $this->bedrockClient(new MockHandler(array_map(
            fn(array $result): Result => new Result($result),
            $results,
        )));
    }

    protected function bedrockClient(MockHandler $mock): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => false,
            'retries' => 0,
            'handler' => $mock,
        ]);
    }

    protected function gatewayWithClient(BedrockRuntimeClient $client): BedrockTextGateway
    {
        return new class ($client) extends BedrockTextGateway
        {
            public function __construct(private BedrockRuntimeClient $stub)
            {
                parent::__construct(EventManager::instance());
            }

            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    protected function gatewayWithHandler(MockHandler $mock): BedrockTextGateway
    {
        return new class ($mock) extends BedrockTextGateway
        {
            public function __construct(private MockHandler $mock)
            {
                parent::__construct(EventManager::instance());
            }

            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                $client = parent::createBedrockClient($provider, $timeout);
                $client->getHandlerList()->setHandler($this->mock);

                return $client;
            }
        };
    }

    protected function bedrockProvider(): BedrockProvider
    {
        return new BedrockProvider(
            config: [
                'name' => 'bedrock',
                'driver' => 'bedrock',
                'region' => 'us-east-1',
                'use_default_credential_provider' => false,
            ],
            events: EventManager::instance(),
        );
    }

    protected function rerankingGatewayWithClient(BedrockRuntimeClient $client): BedrockRerankingGateway
    {
        return new class ($client) extends BedrockRerankingGateway
        {
            public function __construct(private BedrockRuntimeClient $stub)
            {
            }

            protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
            {
                return $this->stub;
            }
        };
    }

    protected function fakeBedrockRerankingResponse(): array
    {
        return [
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
        ];
    }

    protected function mockBedrockException(string $awsErrorCode, int $statusCode = 400, string $message = 'Bedrock error'): BedrockRuntimeException
    {
        return new class ($awsErrorCode, $statusCode, $message) extends BedrockRuntimeException
        {
            public function __construct(
                private string $awsErrorCode,
                private int $httpStatus,
                string $message,
            ) {
                parent::__construct($message, new Command('InvokeModel', []));
            }

            public function getAwsErrorCode(): string
            {
                return $this->awsErrorCode;
            }

            public function getStatusCode(): int
            {
                return $this->httpStatus;
            }
        };
    }

    protected function contentBlockStart(int $index, array $start = []): array
    {
        $payload = ['contentBlockIndex' => $index];

        if ($start !== []) {
            $payload['start'] = $start;
        }

        return ['contentBlockStart' => $payload];
    }

    protected function contentBlockDelta(int $index, array $delta): array
    {
        return [
            'contentBlockDelta' => [
                'contentBlockIndex' => $index,
                'delta' => $delta,
            ],
        ];
    }

    protected function contentBlockStop(int $index): array
    {
        return [
            'contentBlockStop' => ['contentBlockIndex' => $index],
        ];
    }

    protected function messageStop(string $stopReason): array
    {
        return [
            'messageStop' => ['stopReason' => $stopReason],
        ];
    }

    /**
     * Run a single generation step and return the parameters sent to the Converse API.
     */
    protected function capturedConverseParameters(?TextGenerationOptions $options = null, array $tools = [], ?string $instructions = 'You are a helpful assistant.'): array
    {
        $captured = [];

        $client = $this->bedrockClient(new MockHandler([function ($command) use (&$captured): Result {
            $captured = $command->toArray();

            return new Result([
                'output' => ['message' => ['content' => [['text' => 'Hello']]]],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
                'stopReason' => 'end_turn',
            ]);
        }]));

        (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            $instructions,
            tools: $tools,
            options: $options,
        );

        return $captured;
    }
}
