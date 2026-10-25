<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

/**
 * Thrown when a stop predicate aborts a streaming provider response.
 *
 * Intentionally not failoverable: a stopped run must never retry
 * against another provider.
 */
class StoppedException extends AiException
{
}
