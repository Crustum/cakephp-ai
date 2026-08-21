<?php
declare(strict_types=1);

namespace Crustum\Ai\Approvals;

use Cake\Collection\CollectionInterface;
use Cake\Http\Client\Response;
use Crustum\Ai\Exception\AiException;

/**
 * Thrown when resume decisions do not match pending tool approvals.
 */
class ApprovalMismatchException extends AiException
{
    /**
     * @param string $message Exception message
     * @param \Cake\Collection\CollectionInterface<int, \Crustum\Ai\Approvals\PendingApproval> $pendingApprovals Pending approvals
     */
    public function __construct(string $message, public CollectionInterface $pendingApprovals)
    {
        parent::__construct($message);
    }

    /**
     * Render the exception as a 409 response carrying the pending approvals.
     *
     * @return \Cake\Http\Client\Response
     */
    public function render(): Response
    {
        return new Response(['HTTP/1.1 409 Conflict'], json_encode([
            'message' => $this->getMessage(),
            'approvals' => array_map(
                fn(PendingApproval $approval): array => $approval->toArray(),
                $this->pendingApprovals->toList(),
            ),
        ]));
    }
}
