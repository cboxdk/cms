<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What IdempotencyStore::claim() returns. There are exactly four results, and a store returns no
 * other class:
 *
 * - Fresh: no completed record for the key; the caller runs the command and calls complete()
 *   with the token when it commits a changeset.
 * - Replay: the key was completed with the same content hash; the caller returns the stored
 *   changeset's receipt from the ReceiptStore instead of running the command again.
 * - Conflict: the key was completed with another content hash; the command is rejected with
 *   idempotency_conflict.
 * - InFlight: another open transaction holds the claim on the key, and the wait budget ran out.
 */
#[Experimental]
interface ClaimResult {}
