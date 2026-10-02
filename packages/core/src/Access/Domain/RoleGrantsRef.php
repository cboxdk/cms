<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The set of grants a role has had (PRD 5.10): its version is one more than the number of grants
 * ever given of the role, ended ones included, so it moves with every grant.assign of the role and
 * with nothing else. role.set_permissions reads it with the role's grants, so a grant of the role
 * given after the change read them, which the escalation guard did not see, makes the change
 * version_conflict. A revocation moves the grant it ends, which the change reads too.
 *
 * Its key sorts after the role's ("role:" before "role_grants:"), so the commit locks the role
 * first: an assign holds the role FOR SHARE until it commits, and the change's FOR NO KEY UPDATE
 * waits for it, so the count it then reads is final.
 */
#[Internal]
final readonly class RoleGrantsRef implements AggregateRef
{
    public const string KIND = 'role_grants';

    public function __construct(public RoleId $role) {}

    /**
     * "role_grants:" and the role.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return self::KIND.':'.$this->role->toString();
    }
}
