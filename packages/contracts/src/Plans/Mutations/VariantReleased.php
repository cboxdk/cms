<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Override;

/**
 * A revision of one variant of an entry becomes its released revision, the one readers see
 * where a placement makes it visible (PRD 5.4, 5.6, 5.7, 6.4).
 *
 * It names the entry's type, so the kernel checks that the type has stages to release and
 * validates the revision against that type's rules at the release stage before it commits
 * (invariant 5). A release makes content public (ChangesPublicVisibility), so the kernel
 * refuses it to an agent with agent_visibility_forbidden (invariant 18).
 */
#[Experimental]
final readonly class VariantReleased implements ChangesPublicVisibility
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public VariantKey $variant,
        public RevisionNumber $revision,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return new VariantRef($this->entry, $this->variant);
    }

    /**
     * A release always makes its revision what the public sees where a placement shows the variant.
     */
    #[Override]
    public function makesPublic(): bool
    {
        return true;
    }
}
