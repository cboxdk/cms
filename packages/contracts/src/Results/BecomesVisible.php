<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\PlacementId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One placement in one locale that a write makes visible to the public, as a dry run shows it
 * (PRD 6.4): the placement, the locale and the time it becomes visible, in UTC: the time the
 * command read at for a placement it makes visible now, and the start of its window for one it
 * makes visible later.
 */
#[Experimental]
final readonly class BecomesVisible
{
    public DateTimeImmutable $from;

    public function __construct(
        public PlacementId $placement,
        public Locale $locale,
        DateTimeImmutable $from,
    ) {
        $this->from = $from->setTimezone(new DateTimeZone('UTC'));
    }
}
