<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * A DateTimeImmutable that changes itself, to prove the suite checks immutability by behaviour.
 */
final class SelfChangingTime extends DateTimeImmutable
{
    #[Override]
    public function modify(string $modifier): SelfChangingTime
    {
        $this->__construct('2000-01-01T00:00:00', new DateTimeZone('UTC'));

        return $this;
    }
}
