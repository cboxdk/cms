<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * One variant head as entry.get gives it (PRD 5.4, 5.6): the variant, its version, the number of
 * its draft revision, the highest number any of its revisions has, the number of its released
 * revision or null, its release state, the schema version the draft revision was written under,
 * and the fields of the draft revision, read as the type is now and stripped to the reader's
 * classification access, and empty when the revision was written under another schema version
 * than the type's, whose fields the type cannot read (upcasters come with B3).
 */
#[Experimental]
final readonly class EntryHead
{
    public function __construct(
        public VariantKey $variant,
        public AggregateVersion $version,
        public RevisionNumber $revision,
        public RevisionNumber $latest,
        public ?RevisionNumber $released,
        public ReleaseState $state,
        public int $schemaVersion,
        public FieldValues $fields,
    ) {}

    public function withFields(FieldValues $fields): self
    {
        return new self($this->variant, $this->version, $this->revision, $this->latest, $this->released, $this->state, $this->schemaVersion, $fields);
    }
}
