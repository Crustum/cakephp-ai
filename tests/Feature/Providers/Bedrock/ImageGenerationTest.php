<?php
declare(strict_types=1);

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Crustum\Ai\Contracts\Providers\Provider;
use Crustum\Ai\Gateway\Bedrock\BedrockImageGateway;
use Crustum\Ai\Test\Feature\Providers\Bedrock\BedrockHelpersTrait;

uses(BedrockHelpersTrait::class);

test('nested provider options are merged beneath the core image body', function (): void {
    $mock = $this->bedrockInvokeMock(['images' => [base64_encode('fake-image')]]);

    $gateway = new class ($this->bedrockClient($mock)) extends BedrockImageGateway {
        public function __construct(private BedrockRuntimeClient $stub)
        {
        }

        protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
        {
            return $this->stub;
        }
    };

    $gateway->generateImage(
        $this->bedrockProvider(),
        'amazon.titan-image-generator-v2:0',
        'A red apple',
        providerOptions: ['imageGenerationConfig' => ['seed' => 42, 'numberOfImages' => 5]],
    );

    $body = json_decode($mock->getLastCommand()['body'], true);

    expect($body['imageGenerationConfig']['seed'])->toBe(42)
        ->and($body['imageGenerationConfig']['numberOfImages'])->toBe(1)
        ->and($body['textToImageParams']['text'])->toBe('A red apple');
});
