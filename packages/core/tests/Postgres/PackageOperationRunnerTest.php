<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationInsideTransaction;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Tests\Operations\Fakes\RecordingChunkedAction;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Cbox\Operations\Contracts\Operations;
use Cbox\Operations\DataObjects\AdvanceStep;
use Cbox\Operations\DataObjects\StartOperation;
use Cbox\Operations\Enums\OperationStatus;
use Cbox\Operations\Models\Operation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;

/**
 * What PackageOperationRunner does on laravel-operations and real Postgres beyond
 * OperationRunnerBehaviour: the record it keeps in the package's table, the locks that keep two
 * runs of one key apart, and what it refuses. A lock another session holds is seen through a short
 * lock_timeout on the runner's connection, which turns the wait into SQLSTATE 55P03.
 */
final class PackageOperationRunnerTest extends TestCase
{
    use RealPostgres;

    #[Override]
    protected function tearDown(): void
    {
        DB::statement('reset lock_timeout');
        app(IndependentConnections::class)->closeAll();

        parent::tearDown();
    }

    #[Test]
    public function the_operation_is_a_record_of_laravel_operations_with_a_step_per_chunk(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2'], failOnceAt: 'c2');

        try {
            app(OperationRunner::class)->run(new OperationRequest($action, new OperationKey('run-1')));
        } catch (RuntimeException) {
        }

        $operation = Operation::query()->sole();

        self::assertStringStartsWith('op_', $operation->id);
        self::assertSame('scratch.rebuild', $operation->kind);
        self::assertSame('scratch.rebuild', $operation->target_type);
        self::assertSame('run-1', $operation->target_id);
        self::assertSame(OperationStatus::Running, $operation->status);
        self::assertSame(
            [['name' => 'c1', 'status' => 'completed'], ['name' => 'c2', 'status' => 'running']],
            array_map(static fn (array $step): array => ['name' => $step['name'] ?? null, 'status' => $step['status'] ?? null], $operation->steps ?? []),
        );

        app(OperationRunner::class)->run(new OperationRequest($action, new OperationKey('run-1')));

        $operation = Operation::query()->sole();
        self::assertSame(OperationStatus::Completed, $operation->status);
        self::assertNotNull($operation->finished_at);
    }

    #[Test]
    public function it_refuses_to_run_inside_an_open_transaction_and_starts_nothing(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1']);
        $refused = new stdClass;
        $refused->message = null;

        DB::transaction(static function () use ($action, $refused): void {
            try {
                app(OperationRunner::class)->run(new OperationRequest($action, new OperationKey('run-1')));
            } catch (OperationInsideTransaction $refusal) {
                $refused->message = $refusal->getMessage();
            }
        });

        self::assertIsString($refused->message);
        self::assertStringContainsString('transaction level 1', $refused->message);
        self::assertSame([], $action->ran);
        self::assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function finding_or_starting_the_operation_waits_for_the_lock_of_its_kind_and_key(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1']);

        $this->whileAnotherSessionHolds(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['cbox-cms.operation:scratch.rebuild|run-1'],
            function () use ($action): void {
                $this->assertLockTimeout(static fn (): mixed => app(OperationRunner::class)->run(new OperationRequest($action, new OperationKey('run-1'))));
            },
        );

        self::assertSame([], $action->ran);
        self::assertSame(0, Operation::query()->count());

        // Another key has another lock.
        app(OperationRunner::class)->run(new OperationRequest($action, new OperationKey('run-2')));
        self::assertSame(['c1'], $action->ran);
    }

    #[Test]
    public function recording_a_chunk_waits_for_the_row_lock_of_the_operation(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2'], failOnceAt: 'c2');
        $request = new OperationRequest($action, new OperationKey('run-1'));

        try {
            app(OperationRunner::class)->run($request);
        } catch (RuntimeException) {
        }

        $id = Operation::query()->sole()->id;

        $this->whileAnotherSessionHolds('select id from operations where id = ? for update', [$id], function () use ($request): void {
            $this->assertLockTimeout(static fn (): mixed => app(OperationRunner::class)->run($request));
        });

        self::assertSame(['c1', 'c2'], $action->ran);
        self::assertSame(['c1'], app(OperationRunner::class)->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'))?->completedNames());
    }

    #[Test]
    public function completing_the_operation_waits_for_its_row_lock(): void
    {
        // A run that died after its last chunk and before the completion left this behind.
        $operations = app(Operations::class);
        $operation = $operations->start(new StartOperation(kind: 'scratch.rebuild', targetType: 'scratch.rebuild', targetId: 'run-1', steps: ['c1']));
        $operations->advanceStep(new AdvanceStep($operation->id, 'c1'));
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1']);
        $request = new OperationRequest($action, new OperationKey('run-1'));

        $this->whileAnotherSessionHolds('select id from operations where id = ? for update', [$operation->id], function () use ($request): void {
            $this->assertLockTimeout(static fn (): mixed => app(OperationRunner::class)->run($request));
        });

        self::assertSame(OperationStatus::Running, Operation::query()->sole()->status);

        $completed = app(OperationRunner::class)->run($request);

        self::assertSame([], $action->ran);
        self::assertSame(['c1'], $completed->completedNames());
        self::assertSame(OperationStatus::Completed, Operation::query()->sole()->status);
    }

    #[Test]
    public function a_stored_step_without_a_name_cannot_be_read(): void
    {
        Operation::query()->create([
            'kind' => 'scratch.rebuild',
            'target_type' => 'scratch.rebuild',
            'target_id' => 'run-1',
            'status' => OperationStatus::Running,
            'steps' => [['status' => 'running']],
        ]);

        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('a step has no name');

        app(OperationRunner::class)->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'));
    }

    #[Test]
    public function it_refuses_an_operation_model_that_is_not_the_packages(): void
    {
        config()->set('operations.models.operation', stdClass::class);

        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('[operations.models.operation]');

        app(OperationRunner::class)->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'));
    }

    /**
     * Runs $while while an independent session holds the lock that $lock takes in its open
     * transaction, with a lock_timeout of 200 ms on the default connection.
     *
     * @param  list<string>  $bindings
     * @param  Closure(): void  $while
     */
    private function whileAnotherSessionHolds(string $lock, array $bindings, Closure $while): void
    {
        [$other] = app(IndependentConnections::class)->open(1);
        $other->beginTransaction();
        $other->select($lock, $bindings);
        DB::statement("set lock_timeout = '200ms'");

        try {
            $while();
        } finally {
            DB::statement('reset lock_timeout');
            $other->rollBack();
        }
    }

    /**
     * @param  Closure(): mixed  $run
     */
    private function assertLockTimeout(Closure $run): void
    {
        try {
            $run();
            self::fail('The run did not wait for the lock.');
        } catch (QueryException $timeout) {
            self::assertSame('55P03', $timeout->getCode());
        }
    }
}
