<?php

declare(strict_types=1);

// The same writes to kernel tables in an addon, its tests and the testkit outside its fixture
// writers, where they are reported, and in the core and the fixture writers, where they are not.

namespace Acme\Shop\Adapter {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;
    use PDO;

    final readonly class ShopWriter
    {
        public function __construct(private ConnectionInterface $connection, private PDO $pdo) {}

        public function builder(): void
        {
            $this->connection->table('actors')->insert(['id' => 'a']);
            DB::table('nodes')->where('id', 'a')->update(['version' => 2]);
            $this->connection->table('public.entries as e')->where('id', 'a')->delete();
            $this->connection->query()->from('sites')->upsert([], ['id']);
            DB::table('"variant_heads"')->truncate();
            $this->connection->table('events_interactive_p0000000000001000000')->insertOrIgnore([]);
        }

        public function sql(): void
        {
            $this->connection->statement('insert into receipts (changeset_id) values (?)', ['a']);
            DB::statement('UPDATE "public"."placements" SET version = 2');
            $this->connection->delete('delete from only revisions where id = ?', [1]);
            DB::unprepared('truncate table grants, changesets, head_snapshots');
            $this->connection->select('with moved as (update node_routes set route = ? returning 1) select 1', ['/']);
            $this->pdo->exec('merge into mount_overrides using x on true when matched then delete');
        }

        public function allowed(): void
        {
            // Reads, writes to the addon's own tables and an upsert clause.
            $this->connection->table('actors')->count();
            $this->connection->select('select * from nodes for update');
            $this->connection->table('shop_orders')->insert(['id' => 'a']);
            $this->connection->statement('insert into shop_orders (id) values (?) on conflict (id) do update set id = excluded.id', ['a']);
        }
    }
}

namespace Acme\Shop\Tests {
    use Illuminate\Database\ConnectionInterface;

    final readonly class SeedsActors
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('actors')->insert(['id' => 'a']);
        }
    }
}

namespace Cbox\Cms\Core\Identity\Adapter {
    use Illuminate\Database\ConnectionInterface;

    final readonly class CoreWriter
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('actors')->insert(['id' => 'a']);
        }
    }
}

namespace Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter {
    use Illuminate\Database\ConnectionInterface;

    final readonly class FixtureWriter
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('actors')->insert(['id' => 'a']);
        }
    }
}

namespace Cbox\Cms\Testkit\Identity\Adapter {
    use Illuminate\Database\ConnectionInterface;

    final readonly class CopiedWriter
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('actors')->insert(['id' => 'a']);
        }
    }
}

namespace Cbox\Cms\CoreExtras {
    use Illuminate\Database\ConnectionInterface;

    final readonly class NotTheCore
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('actors')->insert(['id' => 'a']);
        }
    }
}
