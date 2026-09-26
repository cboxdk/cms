<?php

declare(strict_types=1);

// The same raw SQL in the domain, an action, a Boundary and code outside a layer, where it is
// reported, and in Infrastructure, an Adapter and tests, where it is not.

namespace Fixture\RawSql\Domain {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Query\Builder;
    use Illuminate\Database\Query\Expression;
    use Illuminate\Support\Facades\DB;
    use PDO;

    final class Article extends Model {}

    final readonly class Reports
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function connection(): void
        {
            $this->connection->select('select 1');
            $this->connection->statement('vacuum');
            $this->connection->scalar('select 1');
        }

        public function facade(): void
        {
            DB::select('select 1');
            DB::raw('now()');
        }

        public function builder(Builder $query): void
        {
            $query->whereRaw('a = b')->orderByRaw('c')->selectRaw('d');
        }

        public function model(): void
        {
            Article::whereRaw('a = b');
        }

        public function pdo(PDO $pdo): void
        {
            $pdo->query('select 1');
        }

        public function expression(): Expression
        {
            return new Expression('now()');
        }

        public function queryBuilder(Builder $query): void
        {
            // Not raw: the query builder writes the SQL.
            $query->where('a', 1)->orderBy('b')->insert(['a' => 1]);
            $query->update(['a' => 2]);
            $query->delete();
            DB::table('articles')->where('a', 1)->get();
        }
    }
}

namespace Fixture\RawSql\Actions {
    use Illuminate\Database\Query\Builder;

    final readonly class Publish
    {
        public function run(Builder $query): void
        {
            $query->whereRaw('a = b');
        }
    }
}

namespace Fixture\RawSql\Boundary {
    use Illuminate\Database\ConnectionInterface;

    final readonly class Parser
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function run(): void
        {
            $this->connection->insert('insert into a values (1)');
        }
    }
}

namespace Fixture\RawSql {
    use Illuminate\Database\DatabaseManager;

    final readonly class NoLayer
    {
        public function __construct(private DatabaseManager $database) {}

        public function run(): void
        {
            $this->database->statement('select 1');
        }
    }
}

namespace Fixture\RawSql\Infrastructure {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Database\Query\Expression;

    final readonly class Catalog
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function run(): Expression
        {
            $this->connection->select('select 1');

            return new Expression('now()');
        }
    }
}

namespace Fixture\RawSql\Adapter {
    use Illuminate\Database\Query\Builder;
    use Illuminate\Support\Facades\DB;

    final readonly class Store
    {
        public function run(Builder $query): void
        {
            DB::statement('select 1');
            $query->whereRaw('a = b');
        }
    }
}

namespace Fixture\RawSql\Tests {
    use Illuminate\Support\Facades\DB;

    final readonly class Probe
    {
        public function run(): void
        {
            DB::statement('select 1');
        }
    }
}
