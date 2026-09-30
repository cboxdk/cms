<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Releases a revision of an entry's shared variant (PRD 5.6, 6.4), version 1 of variant.release:
 * the entry, the number of the revision to release and the version of the shared variant the
 * caller saw. The revision becomes the one readers see where a placement makes the entry visible.
 *
 * The version is the variant's, which every save and release raises, so a caller that saw another
 * state never releases a revision it did not look at (invariant 11). The kernel validates the
 * revision against its own schema version at the release stage (invariant 5), refuses a type that
 * has no revision to release, and refuses the release to an agent, because it changes public
 * visibility (invariant 18).
 */
#[CommandName('variant.release', version: 1)]
#[Experimental]
final readonly class ReleaseVariant implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public RevisionNumber $revision,
        public AggregateVersion $version,
    ) {}

    /**
     * The shared variant this command releases a revision of.
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
