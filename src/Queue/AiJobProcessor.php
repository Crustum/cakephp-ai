<?php
declare(strict_types=1);

namespace Crustum\Ai\Queue;

use Crustum\Queue\Processor\CallbackProcessor;

/**
 * Extends Crustum/Callback queue processor.
 *
 * Rewrite of `Cake\Queue\Queue\Processor` event flow (`seen/start/success/
 * reject/failure/exception/invalid`) with two deliberate differences:
 *
 * - Poison messages REJECT instead of killing the worker: the
 *   `getTarget()`/resolution guard is widened from `RuntimeException|Error`
 *   to `Throwable` (League Container throws `NotFoundException`, neither of
 *   the base catches).
 * - Callback return semantics: the job's return value is data, not a
 *   protocol signal. Any return other than an explicit `REJECT` (or an
 *   explicit requeue `Result`) is success — the message is ACKed and a
 *   `success` event fires. Only a `Throwable` fails the message (requeue +
 *   `exception` event). Upstream jobs return response objects; requiring an
 *   `ACK` string would misclassify every success as a failure.
 *
 * The processor references no job-layer types. Completion callbacks travel
 * in the payload (`then` / `catch` packed-callback lists) and run wherever
 * the job runs; a `failed(Throwable, array)` hook is invoked when the job
 * object provides one (duck-typed, never required). Jobs stay plain
 * message handlers: `execute(Message): mixed` runs, throws on failure.
 *
 * `SyncJobRunner` honors the configured `processor`, so sync, async, and
 * tests share this one lifecycle.
 */
class AiJobProcessor extends CallbackProcessor
{
}
