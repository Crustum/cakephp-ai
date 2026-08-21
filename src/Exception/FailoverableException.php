<?php
declare(strict_types=1);

namespace Crustum\Ai\Exception;

use Throwable;

/**
 * Interface for exceptions that support provider failover.
 *
 * When an exception implements this interface, the system will
 * attempt to failover to the next configured provider.
 */
interface FailoverableException extends Throwable
{
}
