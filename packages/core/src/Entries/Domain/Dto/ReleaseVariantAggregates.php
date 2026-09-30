<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What variant.release read (PRD 6.2 phase 1): the entry with the head of its shared variant, or
 * null when the entry does not exist or the actor's regions do not reach it. The entry is read at
 * its version and only locked for share at commit, so a release waits for a change of the entry
 * itself; the variant is the aggregate the release changes.
 */
#[Internal]
final readonly class ReleaseVariantAggregates implements Aggregates
{
    public function __construct(
        public EntryId $entry,
        public ?StoredEntry $stored,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->entry, $this->stored?->version),
            new ReadVersion(new VariantRef($this->entry, VariantKey::shared()), $this->stored?->head?->version),
        );
    }
}
