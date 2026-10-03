<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\Dto\PermissionsHeld;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Override;

/**
 * HeldPermissions without a database: the context from FakeAccessContexts, and each name decided
 * with the PermissionRule from the grants FakePermissions holds, for the actor and every actor of
 * its chain, as TransactionalHeldPermissions decides it from Postgres. It records every principal
 * and the names it was asked for. HeldPermissionsBehaviour holds it to the adapter.
 */
final class FakeHeldPermissions implements HeldPermissions
{
    /** @var list<array{Principal, list<CommandName>}> */
    public array $asked = [];

    public function __construct(
        public readonly FakePermissions $permissions = new FakePermissions([]),
        public readonly FakeAccessContexts $contexts = new FakeAccessContexts,
    ) {}

    #[Override]
    public function of(Principal $principal, array $permissions): PermissionsHeld
    {
        $this->asked[] = [$principal, $permissions];
        $access = $this->contexts->for($principal);

        if (! $principal instanceof ActorPrincipal) {
            return new PermissionsHeld($access, []);
        }

        $rule = new PermissionRule;
        $chain = [$principal->actor, ...$principal->onBehalfOf];

        return new PermissionsHeld($access, array_values(array_filter(
            $permissions,
            fn (CommandName $permission): bool => array_all($chain, fn (ActorId $actor): bool => $rule->query($permission, $this->permissions->of($actor, $permission))->allowed()),
        )));
    }
}
