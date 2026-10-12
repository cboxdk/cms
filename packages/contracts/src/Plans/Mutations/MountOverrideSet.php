<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Override;

/**
 * A site's exception to a mount is set for one entry (PRD 5.8, 6.6 rung 6): the mount node, the
 * entry, and whether the site hides the entry on the mount. Hidden, the entry answers 404 at the
 * mount's URLs while its source keeps showing it; not hidden, the override is removed and the mount
 * shows the entry as its source does. The mount node is the aggregate, so a second command that
 * read the mount before this one committed is version_conflict.
 *
 * Taking the exception off an entry that its source shows now or later makes that content public
 * on the mount's site, so the mutation makes content public then, and an agent or a token may not
 * plan it (invariant 18); $visible says so, as the action read it.
 */
#[Experimental]
final readonly class MountOverrideSet implements ChangesPublicVisibility
{
    public function __construct(
        public NodeId $mount,
        public EntryId $entry,
        public bool $hidden,
        public bool $visible,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->mount;
    }

    /**
     * Un-hiding makes content public when the source shows the entry now or later.
     */
    #[Override]
    public function makesPublic(): bool
    {
        return ! $this->hidden && $this->visible;
    }
}
