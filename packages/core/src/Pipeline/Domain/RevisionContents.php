<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;

/**
 * The stored content of a revision, as the command pipeline validates a release against it (PRD
 * 6.2 phase 5, invariant 5): the schema version the revision was written under and its fields. It
 * runs in the command transaction under the call's actor context, so a revision the actor's regions
 * do not reach reads as absent. It is one lookup by key, whatever else the variant holds (GUARDRAILS
 * 4.1), writes nothing and takes no lock.
 */
#[Internal]
interface RevisionContents
{
    /**
     * The revision's content, read with the type as the installation has it, or null when the
     * variant has no revision with the number. The fields are null when the revision was written
     * under another schema version than the type's, whose fields the type cannot read.
     */
    public function find(EntryId $entry, VariantKey $variant, RevisionNumber $revision, TypeDefinition $type): ?RevisionContent;
}
