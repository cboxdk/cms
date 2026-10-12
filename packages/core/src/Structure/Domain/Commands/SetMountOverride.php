<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Sets a site's exception to a mount for one entry (PRD 5.8), version 1 of mount.set_override: the
 * mount node, the version the caller read it at, the entry, and whether the site hides the entry
 * on the mount. Hidden, the entry answers 404 at the mount's URLs while its source keeps showing
 * it (PRD 6.6 rung 6); not hidden, the override is removed again and the mount shows the entry as
 * its source does. The priority cap of PRD 5.8 is not in the command: it comes with curation.
 *
 * The exception is decided on the mount's node (PRD 5.10), so the issuing actor needs
 * mount.set_override there, and a mount its grants do not reach is unauthorized. A node that is
 * not a mount, an archived mount, an entry that does not exist, and un-hiding an entry that is not
 * hidden are validation_failed. Un-hiding an entry its source shows now or later makes that
 * content public on the mount's site, which an agent or a token may not do
 * (agent_visibility_forbidden, invariant 18).
 */
#[CommandName('mount.set_override', version: 1)]
#[Experimental]
final readonly class SetMountOverride implements ExpectsVersions
{
    public function __construct(
        public NodeId $mount,
        public AggregateVersion $version,
        public EntryId $entry,
        public bool $hidden,
    ) {}

    /**
     * The mount node, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->mount, $this->version));
    }
}
