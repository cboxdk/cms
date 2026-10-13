<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

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
 * Deletes one translation of an entry (PRD 10.3), version 1 of variant.delete: the entry, the
 * language's variant and the version of it the caller read. The variant's head is withdrawn and
 * its rows in the type's table are removed, and every placement of the entry in that language, on
 * every site, is withdrawn, so its URL answers 410, gone (PRD 8.10). The entry stays active, and
 * so do its shared variant, its other languages and their placements. Withdrawn is sticky: only a
 * reinstatement changes it (invariant 7).
 *
 * The command takes a language's variant of a type with localization variants; the shared
 * variant, a type without localization and a variant withdrawn already are validation_failed. It
 * is decided on the entry's home in the variant's locale (PRD 5.10), so a translator granted that
 * language may delete it and one without it is unauthorized.
 */
#[CommandName('variant.delete', version: 1)]
#[Experimental]
final readonly class DeleteVariant implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public AggregateVersion $version,
    ) {}

    /**
     * The variant this command deletes.
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
