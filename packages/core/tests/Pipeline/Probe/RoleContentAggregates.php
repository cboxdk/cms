<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\Dto\RoleContentChange;
use Cbox\Cms\Core\Access\Domain\GuardedRoleContent;
use Override;

/**
 * Aggregates that read nothing and change a role as a test names it: authorized anywhere, as
 * role.create's and role.set_permissions' are, and held to the escalation guard on the role's
 * content.
 */
final readonly class RoleContentAggregates implements GuardedRoleContent
{
    public function __construct(private RoleContentChange $change) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }

    #[Override]
    public function roleContent(): RoleContentChange
    {
        return $this->change;
    }
}
