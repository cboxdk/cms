<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a commit ended (PRD 6.1, 6.2 phase 7). There are exactly four, in Domain\Dto: Committed with
 * the receipt, a replay of the key's first call included; VersionConflict with the stale reads;
 * IdempotencyConflict, when the key was used with other content; and IdempotencyInFlight, when
 * another call with the key still held it after the wait budget. Only Committed wrote anything.
 */
#[Internal]
interface CommitOutcome {}
