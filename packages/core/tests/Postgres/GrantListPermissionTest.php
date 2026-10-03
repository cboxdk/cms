<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Adapter\PostgresAccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * grant.list decides on the node (PRD 5.10): over the ListingWorld, a reader whose role that may
 * run grant.list reaches one node, and whose other role, which may not, reaches a larger branch,
 * lists the grants of the one node alone, though its regions reach the branch too. The nearest
 * grant of the role that may run grant.list decides, so a deny of it below an allow keeps the
 * nodes under the deny out, whatever the other role reaches there.
 */
final class GrantListPermissionTest extends TestCase
{
    use ListingReads;
    use RealPostgres;

    private const string AUTHOR = '0192a0c0-0000-7000-8000-000000000333';

    private const string LISTER = '0192a0c0-0000-7000-8000-000000000325';

    private const string GRANT_LIST_CULTURE = '0192a0c0-0000-7000-8000-000000000346';

    private const string GRANT_AUTHOR_NEWS = '0192a0c0-0000-7000-8000-000000000347';

    private const string GRANT_LIST_ROOT = '0192a0c0-0000-7000-8000-000000000348';

    private const string GRANT_LIST_DENIED = '0192a0c0-0000-7000-8000-000000000349';

    private const string GRANT_AUTHOR_ROOT = '0192a0c0-0000-7000-8000-00000000034a';

    #[Test]
    public function it_lists_only_the_grants_on_the_node_where_the_reader_may_run_grant_list(): void
    {
        $this->seedLister([
            [self::GRANT_LIST_CULTURE, ListingWorld::DESK, ListingWorld::CULTURE, 'allow', null],
            [self::GRANT_AUTHOR_NEWS, self::AUTHOR, ListingWorld::NEWS, 'allow', null],
        ]);

        // The regions reach CULTURE through desk and NEWS and SPORT through author; grant.list
        // only CULTURE, where desk holds it.
        Assert::assertSame([ListingWorld::GRANT_BOB, self::GRANT_LIST_CULTURE], $this->listedBy());
    }

    #[Test]
    public function it_keeps_out_the_nodes_below_a_deny_of_the_role_that_may_run_grant_list(): void
    {
        $this->seedLister([
            [self::GRANT_LIST_ROOT, ListingWorld::DESK, ListingWorld::ROOT, 'allow', '{en}'],
            [self::GRANT_LIST_DENIED, ListingWorld::DESK, ListingWorld::NEWS, 'deny', null],
            [self::GRANT_AUTHOR_ROOT, self::AUTHOR, ListingWorld::ROOT, 'allow', null],
        ]);

        // author reaches every node; desk, in en, every node but NEWS and SPORT below its deny.
        Assert::assertSame(
            [ListingWorld::GRANT_ADMIN, ListingWorld::GRANT_BOB, self::GRANT_LIST_ROOT, self::GRANT_AUTHOR_ROOT],
            $this->listedBy(),
        );
    }

    /**
     * @return list<string> the ids of the grants LISTER lists
     */
    private function listedBy(): array
    {
        $ids = [];

        $this->readAs(self::LISTER, static function () use (&$ids): void {
            $ids = array_map(
                static fn (ListedGrant $grant): string => $grant->id->toString(),
                new PostgresAccessListings(app(ConnectionResolverInterface::class))->grants(null, 100),
            );
        });

        return $ids;
    }

    /**
     * Writes the role author, which may run entry.revise alone, the staff actor LISTER and its
     * grants as the superuser.
     *
     * @param  list<array{string, string, string, string, string|null}>  $grants  id, role, node, effect, locales
     */
    private function seedLister(array $grants): void
    {
        $superuser = StorageTables::superuser();
        $superuser->table('roles')->insert(['id' => self::AUTHOR, 'handle' => 'author', 'classification_ceiling' => 'internal', 'version' => 1, 'created_at' => ListingWorld::CREATED_AT]);
        $superuser->table('role_permissions')->insert(['role_id' => self::AUTHOR, 'command' => 'entry.revise', 'created_at' => ListingWorld::CREATED_AT]);
        $superuser->table('actors')->insert(['id' => self::LISTER, 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => ListingWorld::CREATED_AT]);

        foreach ($grants as [$id, $role, $node, $effect, $locales]) {
            $superuser->table('grants')->insert([
                'id' => $id,
                'actor_id' => self::LISTER,
                'role_id' => $role,
                'node_id' => $node,
                'effect' => $effect,
                'locales' => $locales,
                'version' => 1,
                'created_at' => ListingWorld::CREATED_AT,
            ]);
        }
    }
}
