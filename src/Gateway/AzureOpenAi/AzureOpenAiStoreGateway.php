<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\AzureOpenAi;

use Crustum\Ai\Gateway\AzureOpenAi\Trait\CreatesAzureOpenAiClientTrait;
use Crustum\Ai\Gateway\OpenAi\OpenAiStoreGateway;

/**
 * Azure OpenAI vector store gateway.
 */
class AzureOpenAiStoreGateway extends OpenAiStoreGateway
{
    use CreatesAzureOpenAiClientTrait;
}
