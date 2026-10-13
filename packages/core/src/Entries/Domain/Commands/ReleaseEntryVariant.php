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
 * Releases a revision of any variant of an entry (PRD 5.6, 6.4, 10.3), version 2 of
 * variant.release: the entry, the variant, the shared one or a language's, the number of the
 * revision to release and the version of the variant the caller saw. Each variant has its own
 * release, so a language goes live on its own; a language's released content is shown where its
 * placement makes the entry visible once the shared variant is released too (invariant 6).
 *
 * The version is the variant's, which every save and release raises, so a caller that saw another
 * state never releases a revision it did not look at (invariant 11). The kernel validates the
 * revision against its own schema version at the release stage (invariant 5), refuses a type that
 * has no revision to release, authorizes a language's release in its locale and the shared
 * variant's in every locale (PRD 5.10), and refuses the release to an agent, because it changes
 * public visibility (invariant 18).
 */
#[CommandName('variant.release', version: 2)]
#[Experimental]
final readonly class ReleaseEntryVariant implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public RevisionNumber $revision,
        public AggregateVersion $version,
    ) {}

    /**
     * The variant this command releases a revision of.
     */
    public function ref(): VariantRef
    {
        return new VariantRef($this->entry, $this->variant);
    }

    /**
     * The variant, at the version the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->ref(), $this->version));
    }
}
