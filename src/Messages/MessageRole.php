<?php
declare(strict_types=1);

namespace Crustum\Ai\Messages;

/**
 * Message Role Enum
 *
 * Defines the role of a message in a conversation.
 */
enum MessageRole: string
{
    case Assistant = 'assistant';
    case User = 'user';
    case ToolResult = 'tool_result';
}
