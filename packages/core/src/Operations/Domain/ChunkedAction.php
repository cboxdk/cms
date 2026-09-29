<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Work that takes longer than a few seconds, split into chunks that each commit on their own
 * (GUARDRAILS 4.2). An OperationRunner runs it as an operation in laravel-operations: every chunk
 * is a step with its own progress, and a run that stops, because a chunk threw or the process
 * died, resumes at the first chunk that has not completed.
 *
 * A chunk must be idempotent: a chunk whose writes committed can run again when the process dies
 * before the runner records it as completed. A kernel chunk gets that from its command's
 * idempotency key, derived from the operation key and the chunk name. A chunk should finish well
 * within 2 seconds per transaction (GUARDRAILS 4.1). An operation never runs in an HTTP request.
 */
#[Experimental]
interface ChunkedAction
{
    /**
     * The kind of work, the same on every run.
     */
    public function kind(): OperationKind;

    /**
     * The chunks in the order they run. Asked once, when the operation starts; a resumed operation
     * keeps the plan it started with.
     */
    public function chunks(): ChunkPlan;

    /**
     * Does the work of one chunk of the plan. An exception stops the run and leaves the operation
     * running, with this chunk not completed.
     */
    public function runChunk(ChunkName $chunk): void;
}
