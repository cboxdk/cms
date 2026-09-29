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
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Cbox\Cms\Core\Pipeline\Domain\SavepointRefused;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\NestedTransactionGuard;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Override;
use RuntimeException;

/**
 * ConnectionCommandTransaction on real Postgres, as the app role, beyond what
 * CommandTransactionBehaviour holds every implementation to: the isolation level and the time
 * limit it sets, the access context, the connection it runs on, a commit that fails, and the
 * nested transactions and savepoints it refuses.
 */
final class ConnectionCommandTransactionTest extends TestCase
{
    use RealPostgres;

    private const string ACTOR = '01936f5e-8a2b-7c3d-9e4f-0000000000c1';

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
            app(CommandTransaction::class)->run($this->access(), function () use ($connection, &$seen): WriteResult {
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

    public function test_it_limits_the_transaction_to_five_seconds_from_its_start_whatever_the_role_s_setting(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $connection->statement("set transaction_timeout = '30s'");
        $seen = null;

        try {
            app(CommandTransaction::class)->run($this->access(), function () use ($connection, &$seen): WriteResult {
                $seen = $connection->scalar("select current_setting('transaction_timeout')");

                return $this->rejected();
            });
        } finally {
            $connection->statement('reset transaction_timeout');
        }

        $this->assertSame('5s', $seen);
        $this->assertSame(5000, CommandTransaction::TIMEOUT_MILLISECONDS);
    }

    public function test_it_ends_a_transaction_that_runs_past_its_limit_even_below_a_longer_role_setting(): void
    {
        // The limit is set as the connection's session setting only to keep the test short; the
        // adapter's own statement restarts the timer the same way at TIMEOUT_MILLISECONDS.
        $connection = app(IndependentConnections::class)->open(1)[0];
        $connection->statement("set transaction_timeout = '30s'");
        $connection->beginTransaction();
        $connection->statement("select set_config('transaction_timeout', '0', true), set_config('transaction_timeout', '200ms', true)");

        try {
            $connection->statement('select pg_sleep(1)');
            $this->fail('The transaction ran past its limit.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('transaction timeout', $exception->getMessage());
        }

        $this->assertStringContainsString("set_config('transaction_timeout', '0', true), set_config('transaction_timeout', ?, true)", ConnectionCommandTransaction::TIMEOUT);
    }

    public function test_it_sets_the_call_s_access_context_for_the_work_and_it_ends_with_the_transaction(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $seen = [];

        app(CommandTransaction::class)->run($this->access(), function () use ($connection, &$seen): WriteResult {
            $seen = [
                $connection->scalar("select current_setting('cbox_cms.principal', true)"),
                $connection->scalar("select current_setting('cbox_cms.actor', true)"),
                $connection->scalar("select current_setting('cbox_cms.classification', true)"),
            ];

            return $this->rejected();
        });

        $this->assertSame(['actor', self::ACTOR, 'internal'], $seen);
        $this->assertSame('', $connection->scalar("select coalesce(current_setting('cbox_cms.actor', true), '')"));
    }

    public function test_it_refuses_a_transaction_the_work_begins_inside_it_and_rolls_back(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $thrown = null;

        try {
            app(CommandTransaction::class)->run($this->access(), function () use ($connection): WriteResult {
                $claim = $this->claim();
                $this->assertInstanceOf(Fresh::class, $claim);
                $this->store()->complete($claim->token, new ChangesetId(new FakeIdGenerator(clock: $this->fakeClock())->next()));
                $connection->beginTransaction();

                return $this->rejected();
            });
        } catch (SavepointRefused $exception) {
            $thrown = $exception->getMessage();
        }

        $this->assertStringStartsWith(sprintf('A transaction was begun on the connection "%s" inside a command transaction', $connection->getName()), (string) $thrown);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame([], app(NestedTransactionGuard::class)->pullViolations());
        $this->assertInstanceOf(Fresh::class, $this->claimAfter());
    }

    public function test_it_refuses_a_savepoint_statement_inside_it(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $refused = [];

        foreach (['savepoint probe', ' RELEASE probe', 'rollback to savepoint probe', 'Rollback To probe'] as $statement) {
            try {
                app(CommandTransaction::class)->run($this->access(), function () use ($connection, $statement): WriteResult {
                    $connection->statement($statement);

                    return $this->rejected();
                });
            } catch (SavepointRefused $exception) {
                $refused[] = $exception->getMessage();
            }
        }

        $this->assertCount(4, $refused);
        $this->assertStringStartsWith('The statement "savepoint probe" was run on the connection', $refused[0] ?? '');
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_it_refuses_nothing_on_the_connection_after_the_command_ended(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        app(CommandTransaction::class)->run($this->access(), fn (): WriteResult => $this->rejected());

        $connection->beginTransaction();

        try {
            $this->assertSame(1, $connection->scalar('select 1'));
            $connection->statement('savepoint outside');
            $connection->statement('release outside');
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_it_leaves_no_transaction_open_after_a_commit(): void
    {
        $connection = app(DatabaseManager::class)->connection();
        $changeset = new ChangesetId(new FakeIdGenerator(clock: $this->fakeClock())->next());

        app(CommandTransaction::class)->run($this->access(), static fn (): WriteResult => WriteResult::committed(Receipt::committed($changeset, WaitLevel::Commit, RetentionClass::Standard, new CommitPosition('4827'))));

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
            app(CommandTransaction::class)->run($this->access(), function () use ($changeset): WriteResult {
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
        app(CommandTransaction::class)->run($this->access(), function () use (&$after): WriteResult {
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
            $named = $this->refusal(new ConnectionCommandTransaction($manager, new SavepointRefusal, $default));
            $unnamed = $this->refusal(new ConnectionCommandTransaction($manager, new SavepointRefusal));
        } finally {
            $manager->connection()->rollBack();
        }

        $this->assertStringStartsWith(sprintf('The connection "%s" already has a transaction open.', $default), $named);
        $this->assertSame($named, $unnamed);
    }

    private function refusal(CommandTransaction $transaction): string
    {
        try {
            $transaction->run($this->access(), fn (): WriteResult => $this->rejected());
        } catch (CommandTransactionOpen $exception) {
            return $exception->getMessage();
        }

        $this->fail('The command transaction ran inside an open transaction.');
    }

    private function claimAfter(): ClaimResult
    {
        $after = null;
        app(CommandTransaction::class)->run($this->access(), function () use (&$after): WriteResult {
            $after = $this->claim();

            return $this->rejected();
        });

        $this->assertInstanceOf(ClaimResult::class, $after);

        return $after;
    }

    private function access(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal(ActorId::fromString(self::ACTOR), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        );
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
