<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\Dto\AssignGrantAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Access\Domain\GuardedGrant;
use Override;

/**
 * Aggregates that read nothing and give the role a test names: authorized on its node in its
 * locales, as grant.assign's are, and held to the escalation guard.
 */
final readonly class GrantingAggregates implements GuardedGrant
{
    public function __construct(private RoleGrant $grant) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AssignGrantAggregates::scope($this->grant->node, $this->grant->locales);
    }

    #[Override]
    public function escalation(): RoleGrant
    {
        return $this->grant;
    }
}
