<?php
declare(strict_types=1);

namespace Crustum\Ai\Gateway\DeepSeek;

use Cake\Event\EventManagerInterface;
use Crustum\Ai\Contracts\Gateway\StepTextGateway;
use Crustum\Ai\Gateway\DeepSeek\Trait\BuildsTextRequestsTrait;
use Crustum\Ai\Gateway\DeepSeek\Trait\CreatesDeepSeekClientTrait;
use Crustum\Ai\Gateway\DeepSeek\Trait\HandlesTextStreamingTrait;
use Crustum\Ai\Gateway\DeepSeek\Trait\MapsMessagesTrait;
use Crustum\Ai\Gateway\DeepSeek\Trait\ParsesTextResponsesTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsAttachmentsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\MapsChatCompletionToolsTrait;
use Crustum\Ai\Gateway\OpenAiCompatible\Trait\PerformsChatCompletionStepsTrait;
use Crustum\Ai\Gateway\Trait\HandlesFailoverErrorsTrait;
use Crustum\Ai\Gateway\Trait\ParsesServerSentEventsTrait;

/**
 * DeepSeek Chat Completions API gateway.
 */
class DeepSeekGateway implements StepTextGateway
{
    use BuildsTextRequestsTrait;
    use CreatesDeepSeekClientTrait;
    use HandlesTextStreamingTrait;
    use MapsAttachmentsTrait;
    use MapsChatCompletionToolsTrait;
    use MapsMessagesTrait;
    use ParsesTextResponsesTrait;
    use PerformsChatCompletionStepsTrait;
    use HandlesFailoverErrorsTrait;
    use ParsesServerSentEventsTrait;

    /**
     * Constructor.
     *
     * @param \Cake\Event\EventManagerInterface $events Event manager instance
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }
}
