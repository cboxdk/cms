<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\Dto\PermissionsHeld;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;

/**
 * HeldPermissions on a database connection (PRD 5.10, 13.4): the default connection, or a named
 * one. It begins a transaction, resolves the principal with the PostgresAccessResolver, which sets
 * the actor's context, reads the actor's grants with their roles' permissions under it, and those
 * of each actor of its chain through PostgresGrants::heldByDelegator(), decides each name with the
 * PermissionRule, and rolls the transaction back, because it wrote nothing and the context it set
 * must end with it. It reads the grants in two statements per actor, however many names it is
 * asked for. A connection that is already in a transaction is refused, as TransactionalAccessContexts
 * refuses it.
 */
#[Internal]
final readonly class TransactionalHeldPermissions implements HeldPermissions
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private AccessCompiler $compiler,
        private PermissionRule $rule,
        private ?string $connection = null,
    ) {}

    /**
     * @throws LogicException when the connection is already in a transaction
     */
    #[Override]
    public function of(Principal $principal, array $permissions): PermissionsHeld
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $connection = $this->connections->connection($name);

        if ($connection->transactionLevel() > 0) {
            throw new LogicException(sprintf('The permissions are read in a transaction of their own, but the connection "%s" is already in a transaction.', $name));
        }

        $connection->beginTransaction();

        try {
            $access = new PostgresAccessResolver($this->connections, $this->compiler, $name)->resolve($principal);

            if (! $principal instanceof ActorPrincipal || $permissions === []) {
                return new PermissionsHeld($access, []);
            }

            $grants = new PostgresGrants($this->connections, $name);
            $chain = [$grants->held($principal->actor), ...array_map(
                $grants->heldByDelegator(...),
                $principal->onBehalfOf,
            )];

            return new PermissionsHeld($access, array_values(array_filter(
                $permissions,
                fn (CommandName $permission): bool => array_all($chain, fn (array $held): bool => $this->rule->query($permission, $this->permitting($held, $permission))->allowed()),
            )));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * The grants of the roles whose permissions name the command or read.
     *
     * @param  list<HeldGrant>  $held
     * @return list<Grant>
     */
    private function permitting(array $held, CommandName $permission): array
    {
        return array_values(array_map(
            static fn (HeldGrant $grant): Grant => $grant->grant,
            array_filter($held, static fn (HeldGrant $grant): bool => $grant->permits($permission)),
        ));
    }
}
