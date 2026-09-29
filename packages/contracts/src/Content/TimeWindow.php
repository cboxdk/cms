<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When something is live: from an instant, until an instant, both, or neither (PRD 5.7,
 * live_from and live_until). The start is inclusive and the end exclusive. A missing start means
 * since always and a missing end means for ever. Both instants are held in UTC, and the start is
 * before the end.
 */
#[Experimental]
final readonly class TimeWindow
{
    public ?DateTimeImmutable $from;

    public ?DateTimeImmutable $until;

    public function __construct(?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null)
    {
        $utc = new DateTimeZone('UTC');
        $from = $from?->setTimezone($utc);
        $until = $until?->setTimezone($utc);

        if ($from instanceof DateTimeImmutable && $until instanceof DateTimeImmutable && $from >= $until) {
            throw InvalidContentValue::timeWindow($from, $until);
        }

        $this->from = $from;
        $this->until = $until;
    }

    /**
     * The window without a start or an end.
     */
    public static function always(): self
    {
        return new self;
    }

    /**
     * Whether the instant is inside the window.
     */
    public function contains(DateTimeImmutable $at): bool
    {
        return (! $this->from instanceof DateTimeImmutable || $at >= $this->from)
            && (! $this->until instanceof DateTimeImmutable || $at < $this->until);
    }

    public function equals(self $other): bool
    {
        return $this->sameInstant($this->from, $other->from) && $this->sameInstant($this->until, $other->until);
    }

    private function sameInstant(?DateTimeImmutable $one, ?DateTimeImmutable $other): bool
    {
        if (! $one instanceof DateTimeImmutable || ! $other instanceof DateTimeImmutable) {
            return ! $one instanceof DateTimeImmutable && ! $other instanceof DateTimeImmutable;
        }

        return $one->format('U.u') === $other->format('U.u');
    }
}
