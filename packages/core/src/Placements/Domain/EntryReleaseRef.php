<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * Whether an entry may be shown, as an aggregate a placement command reads before it makes a
 * placement live or scheduled (PRD 5.7, invariant 6): at the version of the head of the entry's
 * shared variant while the entry is active, and absent otherwise. A release, an unrelease or a
 * revise of the variant that commits between the command's read and its commit changes the
 * version, so the command is version_conflict and never stores a live placement for an entry
 * that is not released any more.
 */
#[Internal]
final readonly class EntryReleaseRef implements AggregateRef
{
    public const string KIND = 'entry_release';

    public function __construct(public EntryId $entry) {}

    /**
     * "entry_release:" and the entry.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return sprintf('%s:%s', self::KIND, $this->entry->toString());
    }
}
