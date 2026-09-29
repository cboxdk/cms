<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Throwable;

/**
 * Runs a chunked action as an operation in laravel-operations (GUARDRAILS 3, 4.2): progress per
 * chunk, idempotent retries and resume. The core binds it to PackageOperationRunner. A console
 * command or a queued job calls it, never an HTTP request.
 */
#[Experimental]
interface OperationRunner
{
    /**
     * Runs the request's action to the end. When the latest operation of the action's kind and the
     * request's key is running, it resumes it at its first chunk that has not completed, with the
     * plan it started with; when it is completed, it runs nothing and returns it; otherwise, with
     * none or a failed one, it starts a new operation with the action's chunk plan. Each chunk is
     * recorded as completed right after it returns.
     *
     * @throws OperationInsideTransaction when a transaction is open on the operations' connection
     * @throws Throwable what a chunk throws; the operation stays running, and a run with the same
     *                   kind and key resumes it at that chunk
     */
    public function run(OperationRequest $request): OperationProgress;

    /**
     * The latest operation of the kind and key: the one that is running or completed, or else the
     * latest failed one. Null when there is none.
     */
    public function find(OperationKind $kind, OperationKey $key): ?OperationProgress;
}
