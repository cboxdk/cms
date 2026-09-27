<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;

/**
 * What the broken sessions of one store share.
 */
final class BrokenIdempotencyState
{
    /** @var array<string, ChangesetId> */
    public array $completed = [];

    /** @var array<string, DateTimeImmutable> the created_at of each completed record */
    public array $createdAt = [];

    public function __construct(public readonly Clock $clock) {}

    /** @var array<string, BrokenIdempotencySession> */
    public array $stuck = [];
}
