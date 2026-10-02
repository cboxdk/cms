<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Access\Domain\EscalationGuard;
use Cbox\Cms\Core\Access\Domain\GuardedRoleContent;
use Cbox\Cms\Core\Access\Domain\RoleGrantsRef;
use Override;

/**
 * What role.set_permissions read (PRD 5.10, 6.2 phase 1): the role with its permissions, or null
 * when no role has the id; the role's grants that have not ended, wherever they are, with the
 * version of its set of grants; and which of the new permissions the registry does not know. The
 * kernel checks the role, its set of grants and each grant at commit under their locks, so a grant
 * of the role given, ended or changed meanwhile is version_conflict.
 *
 * The command is authorized anywhere, so the issuing actor needs role.set_permissions on some
 * node, and what the change adds is held to the escalation guard on every node where the role is
 * granted.
 */
#[Internal]
final readonly class SetRolePermissionsAggregates implements GuardedRoleContent
{
    /**
     * @param  list<CommandName>  $permissions  the new permissions the registry knows, as the command lists them
     * @param  list<CommandName>  $unknown  the new permissions the registry does not know
     */
    public function __construct(
        public RoleId $role,
        public ?StoredRole $stored,
        public ?RoleGrants $grants,
        public array $permissions,
        public array $unknown,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        if (! $this->stored instanceof StoredRole || ! $this->grants instanceof RoleGrants) {
            return new ReadVersions(new ReadVersion($this->role, $this->stored?->version));
        }

        return new ReadVersions(
            new ReadVersion($this->role, $this->stored->version),
            new ReadVersion(new RoleGrantsRef($this->role), $this->grants->version),
            ...array_map(static fn (StoredGrant $grant): ReadVersion => new ReadVersion($grant->id, $grant->version), $this->grants->grants),
        );
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }

    /**
     * The permissions the change adds, the role's grants, and whether the change makes it
     * administrative; null for a role that was not read.
     */
    #[Override]
    public function roleContent(): ?RoleContentChange
    {
        if (! $this->stored instanceof StoredRole || ! $this->grants instanceof RoleGrants) {
            return null;
        }

        return new RoleContentChange(
            $this->role,
            null,
            $this->added(),
            $this->grants->grants,
            ! EscalationGuard::administrative($this->stored->permissions) && EscalationGuard::administrative($this->permissions),
        );
    }

    /**
     * The new permissions the role does not have yet, each once.
     *
     * @return list<CommandName>
     */
    public function added(): array
    {
        $held = [];

        foreach ($this->stored->permissions ?? [] as $permission) {
            $held[$permission->value] = true;
        }

        $added = [];

        foreach ($this->permissions as $permission) {
            if (! isset($held[$permission->value])) {
                $held[$permission->value] = true;
                $added[] = $permission;
            }
        }

        return $added;
    }

    /**
     * Whether the new permissions, known and unknown, are the role's, whatever their order.
     */
    public function unchanged(): bool
    {
        if (! $this->stored instanceof StoredRole) {
            return false;
        }

        return $this->unknown === [] && $this->names($this->permissions) === $this->names($this->stored->permissions);
    }

    /**
     * The names, each once, sorted.
     *
     * @param  list<CommandName>  $permissions
     * @return list<string>
     */
    private function names(array $permissions): array
    {
        $values = array_values(array_unique(array_map(static fn (CommandName $permission): string => $permission->value, $permissions)));
        sort($values);

        return $values;
    }
}
