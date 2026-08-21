<?php
declare(strict_types=1);

namespace Crustum\Ai\Responses\Data;

/**
 * Finish Reason Enum
 *
 * Represents the reason why text generation stopped.
 */
enum FinishReason: string
{
    case Stop = 'stop';
    case ToolCalls = 'tool_calls';
    case Continue = 'continue';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Error = 'error';
    case Unknown = 'unknown';
}
