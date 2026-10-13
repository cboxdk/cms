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
 * Creates a language's variant of an entry from a revision of another language's variant (PRD
 * 9.2, 10.3), version 1 of variant.create_from: the entry, the new variant, the source variant,
 * the number of the source's revision and the version of the source variant the caller read. The
 * new variant's first revision is a copy of the source revision's fields, the starting point of a
 * translation, and the kernel records the derivation translated_from (source, revision). A later
 * release of the source with other content marks the translation stale, which entry.get shows.
 *
 * The new variant is expected to be absent and the source at the version the caller read, so two
 * creates of one variant commit once and a caller that saw another source never copies a revision
 * it did not look at (invariant 11). It is authorized in the new variant's locale on the entry's
 * home (PRD 5.10), so a translator granted one language creates that language. It changes no
 * public visibility; the new variant is released with variant.release or entry.publish.
 */
#[CommandName('variant.create_from', version: 1)]
#[Experimental]
final readonly class CreateVariantFrom implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public VariantKey $source,
        public RevisionNumber $revision,
        public AggregateVersion $version,
    ) {}

    /**
     * The variant this command creates.
     */
    public function target(): VariantRef
    {
        return new VariantRef($this->entry, $this->variant);
    }

    /**
     * The variant whose revision the new variant is made from.
     */
    public function origin(): VariantRef
    {
        return new VariantRef($this->entry, $this->source);
    }

    /**
     * The new variant absent, and the source at the version the caller saw. A command that names
     * one variant as both expects it absent and at a version, which no read satisfies.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        if ($this->variant->equals($this->source)) {
            return new ReadVersions(ReadVersion::at($this->origin(), $this->version));
        }

        return new ReadVersions(ReadVersion::absent($this->target()), ReadVersion::at($this->origin(), $this->version));
    }
}
