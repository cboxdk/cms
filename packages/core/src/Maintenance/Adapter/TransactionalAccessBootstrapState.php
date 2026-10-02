<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Maintenance\Domain\AccessBootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapState;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ExistingRole;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;

/**
 * AccessBootstrapState on Postgres: one call of `cms_access_bootstrap_state(node, handle)`, which
 * runs as the owner role and only under the installation operator's actor context, in a
 * transaction of its own on the default connection, or the one named. The transaction sets the
 * context and is rolled back after the read, because it wrote nothing and the context must end
 * with it. A connection already in a transaction is refused, because the transaction would become
 * a savepoint of the caller's.
 */
#[Internal]
final readonly class TransactionalAccessBootstrapState implements AccessBootstrapState
{
    /** The state, as the owner role; the permissions joined by spaces, which no command name holds. */
    public const string STATE = "select staff_granted, node_exists, role_id::text as role_id, role_ceiling, array_to_string(role_permissions, ' ') as role_permissions from cms_access_bootstrap_state(?::uuid, ?)";

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function read(AccessContext $operator, NodeId $node, RoleHandle $role): BootstrapState
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $db = $this->connections->connection($name);

        if ($db->transactionLevel() > 0) {
            throw new LogicException(sprintf('The bootstrap state is read in a transaction of its own, but the connection "%s" is already in a transaction.', $name));
        }

        $db->beginTransaction();

        try {
            new ActorContext($this->connections, $name)->set($operator);
            $row = GrantRows::row($db->selectOne(self::STATE, [$node->toString(), $role->value], false));
        } finally {
            $db->rollBack();
        }

        $id = GrantRows::text($row, 'role_id', nullable: true);

        return new BootstrapState(
            GrantRows::boolean($row, 'staff_granted'),
            GrantRows::boolean($row, 'node_exists'),
            $id === null ? null : new ExistingRole(
                RoleId::fromString($id),
                ClassificationAccess::from(GrantRows::text($row, 'role_ceiling')),
                $this->permissions(GrantRows::text($row, 'role_permissions')),
            ),
        );
    }

    /**
     * @return list<CommandName>
     */
    private function permissions(string $joined): array
    {
        return $joined === '' ? [] : array_map(static fn (string $name): CommandName => new CommandName($name), explode(' ', $joined));
    }
}
