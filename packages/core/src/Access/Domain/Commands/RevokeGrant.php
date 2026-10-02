<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Ends a grant (PRD 5.10, 6.4), version 1 of grant.revoke: the grant and the version of it the
 * caller read. It ends the grant as a deactivation does: the grant gives nothing from the commit
 * on and stays, ended by the changeset. A grant at another version, or one the issuing actor's
 * regions do not reach, is version_conflict, and a grant that has ended is validation_failed.
 *
 * The issuing actor needs grant.revoke on the grant's node in its locales. Ending a deny gives the
 * actor back what the deny kept from it, so it is held to the escalation guard as a grant of the
 * role would be (invariant 31).
 */
#[CommandName('grant.revoke', version: 1)]
#[Experimental]
final readonly class RevokeGrant implements ExpectsVersions
{
    public function __construct(
        public GrantId $grant,
        public AggregateVersion $version,
    ) {}

    /**
     * The grant, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->grant, $this->version));
    }
}
