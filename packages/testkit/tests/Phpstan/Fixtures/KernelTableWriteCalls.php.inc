<?php

declare(strict_types=1);

// Every write method of the query builder, the roots a chain can start at, SQL in every form the
// rule reads, and calls that only look like writes to a kernel table.

namespace Acme\Shop\Writes {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Database\Query\Builder;
    use Illuminate\Database\Query\Expression;
    use Illuminate\Support\Facades\DB;
    use PDO;

    final class ShopRepository
    {
        public function statement(string $sql): void {}

        public function whereRaw(string $sql): void {}

        public function exec(string $sql): void {}

        public static function make(): mixed
        {
            return null;
        }
    }

    final class ShopNote
    {
        public function __construct(public string $text) {}
    }

    final readonly class EveryWriteMethod
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function write(Builder $query): void
        {
            $this->connection->table('actors')->decrementEach(['version' => 1]);
            $this->connection->table('audit')->increment('version');
            $this->connection->table('entries')->incrementEach(['version' => 1]);
            $this->connection->table('grants')->insertGetId([]);
            $this->connection->table('roles')->insertOrIgnoreUsing([], $query);
            $this->connection->table('sites')->insertUsing([], $query);
            $this->connection->table('nodes')->updateFrom([]);
            $this->connection->table('events')->updateOrInsert([], []);
        }
    }

    final readonly class EveryRoot
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function write(string $method): void
        {
            DB::getFacadeRoot()->table('actors')->insert([]);
            $this->connection::make()->table('audit')->insert([]);
            ShopRepository::make()->table('entries')->insert([]);
            UnknownRoot::make()->table('grants')->insert([]);
            $this->connection->table('roles')->from('shop_orders')->insert([]);
            $this->connection->{$method}('sites')->insert([]);
            $this->connection->table('nodes')->{$method}([]);
        }
    }

    final readonly class EverySql
    {
        public function __construct(private ConnectionInterface $connection, private PDO $pdo) {}

        public function write(bool $flag, string $method, Builder $query, ShopRepository $repository): void
        {
            new Expression('delete from actors');
            DB::unprepared('delete from audit; delete from audit; delete from entries');
            DB::statement($flag ? 'delete from grants' : 'delete from roles');
            $this->connection::statement('delete from sites');
            $this->connection->{$method}('delete from nodes');
            DB::{$method}('delete from nodes');
            UnknownFacade::statement('delete from nodes');
            DB::table('delete from nodes');
            $this->connection->table('delete from nodes');
            $query->where('delete from nodes');
            $this->pdo->quote('delete from nodes');
            $repository->statement('delete from nodes');
            $repository->whereRaw('delete from nodes');
            $repository->exec('delete from nodes');
            new ShopNote('delete from nodes');
            new UnknownNote('delete from nodes');
            new $method('delete from nodes');
        }
    }

    final readonly class EveryChain
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function write(): void
        {
            $this->connection->table('placements')->decrement('version');
            $this->connection?->table('revisions')->delete();
            $this->connection->table(' variant_heads ')->delete();
            sprintf('delete from %s', 'nodes');
        }
    }
}
