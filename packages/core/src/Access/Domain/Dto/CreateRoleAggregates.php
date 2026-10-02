<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\GuardedRoleContent;
use Cbox\Cms\Core\Access\Domain\RoleHandleRef;
use Override;

/**
 * What role.create read (PRD 5.10, 6.2 phase 1): the new role, absent unless a role has its id; its
 * handle, absent unless a role has it; and which of its permissions the registry does not know.
 * The kernel checks the role and the handle at commit under their locks.
 *
 * The command is authorized anywhere, so the issuing actor needs role.create on some node, and its
 * ceiling is held to the escalation guard; the new role is granted nowhere.
 */
#[Internal]
final readonly class CreateRoleAggregates implements GuardedRoleContent
{
    /**
     * @param  list<CommandName>  $permissions  the permissions of the new role the registry knows
     * @param  list<CommandName>  $unknown  the permissions the registry does not know
     */
    public function __construct(
        public RoleId $role,
        public ?AggregateVersion $existing,
        public RoleHandleRef $handle,
        public bool $handleTaken,
        public ClassificationAccess $ceiling,
        public array $permissions,
        public array $unknown,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->role, $this->existing),
            new ReadVersion($this->handle, $this->handleTaken ? AggregateVersion::first() : null),
        );
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }

    /**
     * The new role's ceiling and permissions; it has no grant yet.
     */
    #[Override]
    public function roleContent(): RoleContentChange
    {
        return new RoleContentChange($this->role, $this->ceiling, $this->permissions, [], false);
    }
}
