<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\OpenAiCompatible;

use Crustum\Ai\Gateway\OpenAi\OpenAiClassificationGateway;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\CreatesOpenAiCompatibleClientTrait;

/**
 * OpenAI-compatible Decisions classification gateway.
 */
class OpenAiCompatibleClassificationGateway extends OpenAiClassificationGateway
{
    use CreatesOpenAiCompatibleClientTrait;
}
