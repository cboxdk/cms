<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;

/**
 * Nothing was committed, because the idempotency key was used before with other content (PRD 6.1).
 */
#[Internal]
final readonly class IdempotencyConflict implements CommitOutcome {}
