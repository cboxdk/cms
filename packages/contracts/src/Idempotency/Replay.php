<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * The key was completed with the same content hash: the command already committed this changeset.
 * The caller does not run it again. It resolves the changeset through the ReceiptStore and returns
 * that receipt, with the projection statuses as they are now, so nothing is copied here and
 * nothing goes stale (PRD 6.1: the same key and content return the original result, also when the
 * first call committed but timed out waiting for the edge).
 */
#[Experimental]
final readonly class Replay implements ClaimResult
{
    public function __construct(public ChangesetId $changesetId) {}
}
