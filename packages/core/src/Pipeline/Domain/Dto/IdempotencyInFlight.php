<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;

/**
 * Nothing was committed, because another call with the same idempotency key still held it when the
 * wait budget ran out (PRD 6.1). A retry later gets that call's result.
 */
#[Internal]
final readonly class IdempotencyInFlight implements CommitOutcome {}
