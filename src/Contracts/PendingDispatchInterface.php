<?php
declare(strict_types=1);

namespace Crustum\Ai\Contracts;

/**
 * Pending queued job dispatch handle contract.
 *
 * Lives in Contracts (Foundation layer) so queued response handles can type
 * against it without depending on the Job layer. The callback registrar
 * returned by `getJob()` exposes `then()` / `catch()` methods.
 */
interface PendingDispatchInterface
{
    /**
     * Get the underlying job handle to register completion callbacks.
     *
     * @return object Callback registrar with `then()` / `catch()` methods
     */
    public function getJob(): object;
}
