<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An id value object, such as ChangesetId, that gives its canonical string form (PRD 5.3).
 *
 * An event carries ids, never text (PRD 6.5 invariant 10, 7.2), and an id reaches an event only
 * through this interface: an event payload's properties and an event's aggregate take an
 * Identifier, not a string. The string is 1 to 255 visible ASCII characters without spaces
 * (Events\EventIdentifier::PATTERN), which every id the kernel makes is; an event refuses any
 * other with InvalidEvent.
 */
#[Experimental]
interface Identifier
{
    /**
     * The id's canonical string, the same for two equal ids.
     */
    public function toString(): string;
}
