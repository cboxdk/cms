<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * The head of one variant of an entry as a VariantHeadReader gives it (PRD 5.4, 5.6): the entry
 * with its type and home node, the variant, the variant's version, which a command that revises
 * it expects, the number of its current draft revision, the highest number any of its revisions
 * has, which the next revision follows, the number of its released revision, or null when none is
 * released, its release state, and the fields of the draft revision as the type is now, or null
 * when the revision was written under another schema version than the type's, whose fields the
 * type cannot read.
 *
 * A release writes a published revision of its own after the revision it releases, so the highest
 * number can be above the draft's.
 */
#[Experimental]
final readonly class VariantHead
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public VariantKey $variant,
        public AggregateVersion $version,
        public RevisionNumber $revision,
        public RevisionNumber $latest,
        public ?RevisionNumber $released,
        public ReleaseState $state,
        public ?FieldValues $fields,
    ) {}

    public function ref(): VariantRef
    {
        return new VariantRef($this->entry, $this->variant);
    }
}
