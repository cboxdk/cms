<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Event;
use Override;
use RuntimeException;

/**
 * ConnectionCommandTransaction on real Postgres, as the app role, beyond what
 * CommandTransactionBehaviour holds every implementation to: the isolation level it sets, the
 * connection it runs on, and a commit that fails.
 */
final class ConnectionCommandTransactionTest extends TestCase
{
    use RealPostgres;

    private ?FakeClock $clock = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(PartitionFixtures::class)->coverClock($this->fakeClock(), new DateInterval('P1D'));
    }

    public function test_it_is_the_container_s_command_transaction(): void
    {
        $this->assertInstanceOf(ConnectionCommandTransaction::class, app(CommandTransaction::class));
    }

    public function test_it_runs_the_work_at_read_committed_whatever_the_session_s_default(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->statement("set default_transaction_isolation = 'repeatable read'");
        $seen = [];

        try {
            app(CommandTransaction::class)->run(function () use ($connection, &$seen): WriteResult {
                $row = $connection->selectOne("select current_setting('transaction_isolation') as level");
                $seen = [is_object($row) && property_exists($row, 'level') ? $row->level : null, $connection->transactionLevel()];

                return $this->rejected();
            });
        } finally {
            $connection->statement('reset default_transaction_isolation');
        }

        $this->assertSame(['read committed', 1], $seen);
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_it_leaves_no_transaction_open_after_a_commit(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $changeset = new ChangesetId(new FakeIdGenerator(clock: $this->fakeClock())->next());

        app(CommandTransaction::class)->run(static fn (): WriteResult => WriteResult::committed(Receipt::committed($changeset, WaitLevel::Commit, RetentionClass::Standard, new CommitPosition('4827'))));

        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_it_rolls_back_a_commit_that_fails_and_throws_its_exception(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $failure = new RuntimeException('The commit was refused.');
        Event::listen(TransactionCommitting::class, static fn (): never => throw $failure);
        $changeset = new ChangesetId(new FakeIdGenerator(clock: $this->fakeClock())->next());
        $thrown = null;

        try {
            app(CommandTransaction::class)->run(function () use ($changeset): WriteResult {
                $claim = $this->claim();
                $this->assertInstanceOf(Fresh::class, $claim);
                $this->store()->complete($claim->token, $changeset);

                return WriteResult::committed(Receipt::committed($changeset, WaitLevel::Commit, RetentionClass::Standard, new CommitPosition('4827')));
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        Event::forget(TransactionCommitting::class);

        $this->assertSame($failure, $thrown);
        $this->assertSame(0, $connection->transactionLevel());

        $after = null;
        app(CommandTransaction::class)->run(function () use (&$after): WriteResult {
            $after = $this->claim();

            return $this->rejected();
        });

        $this->assertInstanceOf(Fresh::class, $after);
    }

    public function test_it_names_the_connection_that_already_has_a_transaction_open(): void
    {
        $manager = app(DatabaseManager::class);
        $default = $manager->getDefaultConnection();
        $manager->connection()->beginTransaction();

        try {
            $named = $this->refusal(new ConnectionCommandTransaction($manager, $default));
            $unnamed = $this->refusal(new ConnectionCommandTransaction($manager));
        } finally {
            $manager->connection()->rollBack();
        }

        $this->assertStringStartsWith(sprintf('The connection "%s" already has a transaction open.', $default), $named);
        $this->assertSame($named, $unnamed);
    }

    private function refusal(CommandTransaction $transaction): string
    {
        try {
            $transaction->run(fn (): WriteResult => $this->rejected());
        } catch (CommandTransactionOpen $exception) {
            return $exception->getMessage();
        }

        $this->fail('The command transaction ran inside an open transaction.');
    }

    private function store(): PostgresIdempotencyStore
    {
        return new PostgresIdempotencyStore(app(DatabaseManager::class), $this->fakeClock());
    }

    private function claim(): ClaimResult
    {
        return $this->store()->claim(
            IdempotencyScope::forActor(new PrincipalId('transaction-actor'), new CommandName('probe.rename')),
            new IdempotencyKey('transaction-key'),
            ContentHash::of('transaction-content'),
            WaitBudget::none(),
        );
    }

    private function rejected(): WriteResult
    {
        return WriteResult::rejected(
            Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard),
            new CatalogError(ErrorCode::Unauthorized, null, 'The test rejects this call.'),
        );
    }

    private function fakeClock(): FakeClock
    {
        return $this->clock ??= new FakeClock;
    }
}
