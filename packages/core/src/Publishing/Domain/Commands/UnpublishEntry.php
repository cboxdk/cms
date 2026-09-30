<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Unpublishes an entry (PRD 6.4), version 1 of entry.unpublish, the reverse of entry.publish: it
 * takes the entry's shared variant back to unreleased and closes every placement of the entry that
 * is visible now or later, on every site, in one changeset. It takes the entry and the version of
 * its shared variant the caller saw.
 *
 * It is decided on the entry's home (PRD 5.10), needs no legal basis, and can be reversed: the
 * entry can be published again. A type with stages none has no release to take back, so the
 * command only closes its placements. Withdrawn placements stay withdrawn (invariant 7), and a
 * placement whose window has ended is left as it is.
 */
#[CommandName('entry.unpublish', version: 1)]
#[Experimental]
final readonly class UnpublishEntry implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public AggregateVersion $version,
    ) {}

    /**
     * The shared variant this command unreleases.
     */
    public function variant(): VariantRef
    {
        return new VariantRef($this->entry, VariantKey::shared());
    }

    /**
     * The shared variant, at the version the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->variant(), $this->version));
    }
}
