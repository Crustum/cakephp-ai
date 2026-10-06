<?php
declare(strict_types=1);

namespace Crustum\Ai\Enums;

/**
 * Conversation message status.
 *
 * Replaces the approval timestamp: a turn is either completed or paused for approval.
 */
enum MessageStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
}
