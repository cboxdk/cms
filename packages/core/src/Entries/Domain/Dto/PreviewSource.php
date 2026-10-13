<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * What entry.preview reads of one variant head (PRD 5.4, 5.6): the entry's type and home node, the
 * numbers of the head's draft and released revision, its release state, and the revision it asked
 * for: its number and its fields, or null fields when the variant has no revision of that number
 * (found false) or the revision was written under another schema version than the type's, whose
 * fields the type cannot read (found true).
 */
#[Internal]
final readonly class PreviewSource
{
    public function __construct(
        public TypeId $type,
        public NodeId $home,
        public RevisionNumber $draft,
        public ?RevisionNumber $released,
        public ReleaseState $state,
        public RevisionNumber $revision,
        public bool $found,
        public ?FieldValues $fields,
    ) {}
}
