<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\AzureOpenAi;

use Crustum\Ai\Enums\Lab;
use Crustum\Ai\Gateway\AzureOpenAi\Trait\CreatesAzureOpenAiClientTrait;
use Crustum\Ai\Gateway\OpenAi\OpenAiFileGateway;
use Override;

/**
 * Azure OpenAI Files API gateway.
 */
class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    use CreatesAzureOpenAiClientTrait;

    /**
     * Get the default purpose to use when a file does not specify one.
     *
     * @return string
     */
    #[Override]
    protected function defaultPurpose(): string
    {
        return 'assistants';
    }

    /**
     * Get the provider key used to resolve file upload options.
     *
     * @return \Crustum\Ai\Enums\Lab
     */
    #[Override]
    protected function providerOptionsKey(): Lab
    {
        return Lab::Azure;
    }
}
