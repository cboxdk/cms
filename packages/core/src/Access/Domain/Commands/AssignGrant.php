<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Grants an actor a role on a node (PRD 5.10, 6.4), version 1 of grant.assign: the caller's id of
 * the new grant, the actor, the role, the node, whether it allows or denies, and the locales it
 * holds in, or null for every locale. It expects the grant absent; a grant with the id is
 * version_conflict.
 *
 * The issuing actor needs grant.assign on the node in those locales, and for an allow it must hold
 * every permission of the role there itself, with a classification access there not below the
 * role's ceiling, or the grant is refused with grant_escalation_refused (invariant 31). A grant of
 * an administrative role, one that may run a grant.*, a role.* or actor.deactivate, needs step-up
 * and is refused with step_up_required (PRD 5.16). Only an active staff or service actor gets a
 * grant, and an actor holds a role on a node once: anything else is validation_failed.
 */
#[CommandName('grant.assign', version: 1)]
#[Experimental]
final readonly class AssignGrant implements ExpectsVersions
{
    /**
     * @param  list<Locale>|null  $locales
     */
    public function __construct(
        public GrantId $grant,
        public ActorId $actor,
        public RoleId $role,
        public NodeId $node,
        public GrantEffect $effect,
        public ?array $locales = null,
    ) {}

    /**
     * The grant, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->grant));
    }
}
