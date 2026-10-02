<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Access\Adapter\PostgresGrantReader;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Tests\Access\GrantReaderBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * PostgresGrantReader against GrantReaderBehaviour, as the app role in a transaction with ALICE's
 * context over AccessWorld. The roles and grants are written as the superuser.
 */
final class PostgresGrantReaderBehaviourTest extends TestCase
{
    use GrantReaderBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        AccessWorld::seed();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function readAsAlice(array $roles, array $grants, Closure $read): void
    {
        $superuser = StorageTables::superuser();

        foreach ($roles as $role) {
            $superuser->table('roles')->insert(['id' => $role->id->toString(), 'handle' => 'reader_role', 'classification_ceiling' => $role->ceiling->value, 'version' => $role->version->value, 'created_at' => AccessWorld::CREATED_AT]);

            foreach ($role->permissions as $permission) {
                $superuser->table('role_permissions')->insert(['role_id' => $role->id->toString(), 'command' => $permission->value, 'created_at' => AccessWorld::CREATED_AT]);
            }
        }

        foreach ($grants as $grant) {
            $superuser->table('grants')->insert([
                'id' => $grant->id->toString(),
                'actor_id' => $grant->actor->toString(),
                'role_id' => $grant->role->toString(),
                'node_id' => $grant->node->toString(),
                'effect' => $grant->effect->value,
                'locales' => GrantRows::literal($grant->locales),
                'version' => $grant->version->value,
                'created_at' => AccessWorld::CREATED_AT,
                'ended_changeset_id' => $grant->ended ? AccessWorld::CHANGESET_BOB : null,
            ]);
        }

        $connection = app(DatabaseManager::class)->connection();
        $connection->beginTransaction();

        try {
            app(AccessResolver::class)->resolve(AccessWorld::alice());
            $read(new PostgresGrantReader(app(ConnectionResolverInterface::class)));
        } finally {
            $connection->rollBack();
        }
    }
}
