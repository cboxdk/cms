<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Entries\Domain\RelationStage;

/**
 * Every relation edge of one variant of an entry in one stage (PRD 5.11), which replace the edges
 * the variant had in the stage; an empty list removes them.
 */
#[Internal]
final readonly class VariantEdges
{
    /**
     * @param  list<RelationEdge>  $edges
     */
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
        public RelationStage $stage,
        public array $edges,
    ) {}
}
