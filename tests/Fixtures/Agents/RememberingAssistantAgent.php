<?php
declare(strict_types=1);

namespace Crustum\Ai\Test\Fixtures\Agents;

use Crustum\Ai\Trait\RemembersConversationsTrait;

class RememberingAssistantAgent extends AssistantAgent
{
    use RemembersConversationsTrait;
}
