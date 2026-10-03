<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The database's backstop behind grant.list and actor.list honours deny grants, read as the app
 * role under an actor context without the query pipeline. In the ListingWorld, EDITOR holds desk,
 * which may run grant.list and actor.list, on NEWS and is denied it on SPORT below NEWS: nothing at
 * or below SPORT reaches her through the listing functions or the tables, and she may still list
 * actors, because desk reaches NEWS. A reader whose only grant of a role that may run them is a
 * deny holds neither.
 */
final class ListingBackstopDenyTest extends TestCase
{
    use ListingReads;
    use RealPostgres;

    private const string DENIED_READER = '0192a0c0-0000-7000-8000-000000000326';

    private const string GRANT_DENIED_ROOT = '0192a0c0-0000-7000-8000-00000000034b';

    #[Test]
    public function an_allow_on_a_parent_and_a_deny_on_a_child_hide_everything_below_the_child(): void
    {
        $sport = StorageTables::superuser()->scalar('select path::text from nodes where id = ?::uuid', [ListingWorld::SPORT]);
        Assert::assertIsString($sport);

        $this->readAs(ListingWorld::EDITOR, function () use ($sport): void {
            Assert::assertTrue($this->holds('grant.list'));
            Assert::assertTrue($this->holds('actor.list'));

            // grant.list lists the grant on NEWS alone; the deny on SPORT and the ended grant there stay out.
            Assert::assertSame([ListingWorld::GRANT_EDITOR], $this->ids('select id::text as id from cms_access_grant_list(null, 100)'));

            // The table holds the reader to her own grants: no other actor's grant at or below SPORT.
            Assert::assertSame([], $this->ids(
                'select g.id::text as id from grants g join nodes n on n.id = g.node_id where g.actor_id <> ?::uuid and n.path <@ (select path from nodes where id = ?::uuid)',
                [ListingWorld::EDITOR, ListingWorld::SPORT],
            ));

            // The path label of SPORT, the only text a listing gives about a node, is withheld.
            Assert::assertNull($this->db()->scalar('select cms_access_node_label(?::ltree)', [$sport]));

            // desk reaches NEWS, so she lists the staff actors, and reads no profile but her own.
            Assert::assertSame(
                [ListingWorld::ADMIN, ListingWorld::EDITOR, ListingWorld::BOB],
                $this->ids('select id::text as id from cms_identity_actor_list(null, 100)'),
            );
            Assert::assertSame([ListingWorld::EDITOR], $this->ids('select actor_id::text as id from actor_profiles'));
        });
    }

    #[Test]
    public function a_reader_with_only_a_deny_holds_neither_listing(): void
    {
        $superuser = StorageTables::superuser();
        $superuser->table('actors')->insert(['id' => self::DENIED_READER, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => ListingWorld::CREATED_AT]);
        $superuser->table('grants')->insert([
            'id' => self::GRANT_DENIED_ROOT,
            'actor_id' => self::DENIED_READER,
            'role_id' => ListingWorld::ADMIN_ROLE,
            'node_id' => ListingWorld::ROOT,
            'effect' => 'deny',
            'locales' => null,
            'version' => 1,
            'created_at' => ListingWorld::CREATED_AT,
        ]);

        $this->readAs(self::DENIED_READER, function (): void {
            Assert::assertFalse($this->holds('grant.list'));
            Assert::assertFalse($this->holds('actor.list'));
            Assert::assertSame([], $this->ids('select id::text as id from cms_access_grant_list(null, 100)'));
            Assert::assertSame([], $this->ids('select id::text as id from cms_identity_actor_list(null, 100)'));
            Assert::assertSame([], $this->ids('select actor_id::text as id from actor_profiles'));
        });
    }

    private function holds(string $command): bool
    {
        return $this->db()->scalar('select cms_access_holds_permission(?)', [$command]) === true;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private function ids(string $sql, array $bindings = []): array
    {
        $ids = [];

        foreach ($this->db()->select($sql, $bindings, false) as $row) {
            $id = ((array) $row)['id'] ?? null;
            Assert::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function db(): Connection
    {
        $connection = app(DatabaseManager::class)->connection();
        Assert::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
