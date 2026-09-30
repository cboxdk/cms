<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * Whether an entry has a canonical placement in a locale (PRD 5.7, invariant 14), as an aggregate a
 * placement command reads: at version 1 when one of the entry's placements is canonical there, and
 * absent when none is. Two commands that each find none and would each make a placement canonical
 * commit one after the other, behind the advisory lock the commit takes on an aggregate read as
 * absent, and the second is version_conflict, so the database's "at most one" never fails a
 * commit. A move of the flag between placements changes both placements, whose own versions the
 * commands read too.
 */
#[Internal]
final readonly class CanonicalPlacementRef implements AggregateRef
{
    public const string KIND = 'placement_canonical';

    public function __construct(
        public EntryId $entry,
        public Locale $locale,
    ) {}

    /**
     * "placement_canonical:", the entry and the locale.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return sprintf('%s:%s:%s', self::KIND, $this->entry->toString(), $this->locale->value);
    }
}
