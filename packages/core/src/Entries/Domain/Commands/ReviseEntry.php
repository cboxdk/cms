<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Writes the next revision of an entry's shared variant and moves the variant's head to it
 * (PRD 5.4, 6.4), version 1 of entry.revise: the entry, the version of the shared variant the
 * caller saw, and the variant's fields, all of them, as a revision is a whole snapshot.
 *
 * The version is the variant's, which every save and release raises. A variant at another version,
 * or an entry that does not exist or the caller cannot reach, is version_conflict, so a caller that
 * worked from a stale copy never overwrites a newer change (invariant 11). The fields are validated
 * against the type's current schema version (invariant 4), at the write stage.
 */
#[CommandName('entry.revise', version: 1)]
#[Experimental]
final readonly class ReviseEntry implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public AggregateVersion $version,
        public FieldValues $fields,
    ) {}

    /**
     * The shared variant this command revises.
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
