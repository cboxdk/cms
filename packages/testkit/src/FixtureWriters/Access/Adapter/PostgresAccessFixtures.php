<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\FixtureWriters\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Writes roles and grants to the core's access tables on Postgres as the owner role (PRD 5.10),
 * for tests that run the kernel's access against real Postgres.
 *
 * In M1 roles and grants are created only here: the grant commands, with the guard against
 * escalation (invariant 31), come with the panel (B1). The app role may only read the tables, and
 * their row level security lets only the owner write (see the core's access migration), so the
 * fixtures write on the owner connection. A role holds its permissions, the command names it may
 * run, and a classification ceiling; a grant is an actor, a role, a node and a locale set, null for
 * every locale, allowing or denying, one per actor, role and node.
 */
#[Experimental]
final readonly class PostgresAccessFixtures
{
    public const string ROLES = 'roles';

    public const string PERMISSIONS = 'role_permissions';

    public const string GRANTS = 'grants';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private string $ownerConnection = 'pgsql_owner',
    ) {}

    /**
     * A role with the handle, the ceiling and the permissions.
     *
     * @param  list<CommandName>  $permissions
     */
    public function role(string $handle, ClassificationAccess $ceiling, array $permissions = []): RoleId
    {
        $role = new RoleId($this->ids->next());
        $now = $this->now();

        $this->owner()->transaction(function (ConnectionInterface $owner) use ($role, $handle, $ceiling, $permissions, $now): void {
            $owner->table(self::ROLES)->insert([
                'id' => $role->toString(),
                'handle' => $handle,
                'classification_ceiling' => $ceiling->value,
                'version' => 1,
                'created_at' => $now,
            ]);

            if ($permissions !== []) {
                $owner->table(self::PERMISSIONS)->insert(array_map(static fn (CommandName $command): array => [
                    'role_id' => $role->toString(),
                    'command' => $command->value,
                    'created_at' => $now,
                ], $permissions));
            }
        });

        return $role;
    }

    /**
     * A grant of the role to the actor on the node.
     *
     * @param  list<Locale>|null  $locales  null for every locale
     *
     * @throws InvalidArgumentException for an empty locale set
     */
    public function grant(ActorId $actor, RoleId $role, NodeId $node, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null): GrantId
    {
        if ($locales === []) {
            throw new InvalidArgumentException('A grant holds in every locale (null) or in at least one.');
        }

        $grant = new GrantId($this->ids->next());

        $this->owner()->table(self::GRANTS)->insert([
            'id' => $grant->toString(),
            'actor_id' => $actor->toString(),
            'role_id' => $role->toString(),
            'node_id' => $node->toString(),
            'effect' => $effect->value,
            'locales' => $locales === null ? null : '{'.implode(',', array_map(static fn (Locale $locale): string => $locale->value, $locales)).'}',
            'version' => 1,
            'created_at' => $this->now(),
        ]);

        return $grant;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function owner(): ConnectionInterface
    {
        return $this->connections->connection($this->ownerConnection);
    }
}
