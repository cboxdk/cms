<?php

declare(strict_types=1);

// The same transaction calls and SAVEPOINT strings in Actions, Jobs, an Adapter and tests.

namespace Fixture\Changesets\Domain {
    final class Changeset
    {
        // A domain method named commit() is not a transaction.
        public function commit(): void {}
    }
}

namespace Fixture\Changesets\Actions {
    use Fixture\Changesets\Domain\Changeset;
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;
    use PDO;

    final readonly class SaveChangeset
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function facade(): int
        {
            return DB::transaction(static fn (): int => 1);
        }

        public function begin(): void
        {
            $this->connection->beginTransaction();
        }

        public function savepoint(): void
        {
            $this->connection->statement('SAVEPOINT chunk_1');
        }

        public function nested(): void
        {
            DB::connection()->rollBack();
        }

        public function pdo(PDO $pdo): void
        {
            $pdo->commit();
        }

        public function release(string $name): void
        {
            $this->connection->statement(<<<SQL
                release savepoint {$name}
                SQL);
        }

        public function domain(Changeset $changeset): void
        {
            $changeset->commit();
        }

        public function select(): void
        {
            $this->connection->select('select 1');
        }
    }
}

namespace Fixture\Changesets\Jobs {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;

    final readonly class PruneChangesets
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function handle(): void
        {
            DB::transaction(static fn (): null => null);
            $this->connection->beginTransaction();
            $this->connection->statement('SAVEPOINT prune');
        }
    }
}

namespace Fixture\Changesets\Adapter {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;

    final readonly class PostgresChangesets
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function save(): void
        {
            DB::transaction(static fn (): null => null);
            $this->connection->beginTransaction();
            $this->connection->statement('SAVEPOINT store');
        }
    }
}

namespace Fixture\Changesets\Tests\Actions {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;

    final readonly class SaveChangesetTest
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function test_it_commits(): void
        {
            DB::transaction(static fn (): null => null);
            $this->connection->beginTransaction();
            $this->connection->statement('SAVEPOINT test');
        }
    }
}
