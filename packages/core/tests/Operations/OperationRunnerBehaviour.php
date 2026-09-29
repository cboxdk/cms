<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Operations;

use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\Tests\Operations\Fakes\RecordingChunkedAction;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What every OperationRunner does (GUARDRAILS 4.2), run against PackageOperationRunner on
 * laravel-operations and real Postgres, and against FakeOperationRunner, so the fake cannot drift
 * from the runner (GUARDRAILS 9). The chunked action is the test-only RecordingChunkedAction.
 */
trait OperationRunnerBehaviour
{
    abstract protected function operationRunner(): OperationRunner;

    /** Fails the operation, as laravel-operations' stall sweep or an operator would. */
    abstract protected function failOperation(OperationId $id): void;

    #[Test]
    public function it_runs_every_chunk_in_plan_order_and_completes_the_operation(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2', 'c3']);

        $progress = $this->operationRunner()->run(new OperationRequest($action, new OperationKey('run-1')));

        Assert::assertSame(['c1', 'c2', 'c3'], $action->ran);
        Assert::assertSame(OperationState::Completed, $progress->state);
        Assert::assertSame(['c1', 'c2', 'c3'], $progress->completedNames());
        Assert::assertSame([], $progress->remainingNames());
        Assert::assertSame('scratch.rebuild', $progress->kind->value);
        Assert::assertSame('run-1', $progress->key->value);
        $this->assertSameProgress($progress, $this->operationRunner()->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1')));
    }

    #[Test]
    public function a_failed_chunk_leaves_the_operation_running_and_the_next_run_resumes_at_that_chunk(): void
    {
        $runner = $this->operationRunner();
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2', 'c3'], failOnceAt: 'c2');
        $request = new OperationRequest($action, new OperationKey('run-1'));

        try {
            $runner->run($request);
            Assert::fail('The run went past the chunk that failed.');
        } catch (RuntimeException $failure) {
            Assert::assertSame('The chunk c2 failed.', $failure->getMessage());
        }

        $stopped = $runner->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'));
        Assert::assertNotNull($stopped);
        Assert::assertSame(OperationState::Running, $stopped->state);
        Assert::assertSame(['c1'], $stopped->completedNames());
        Assert::assertSame(['c2', 'c3'], $stopped->remainingNames());
        Assert::assertSame(['c1'], $action->ran);

        $resumed = $runner->run($request);

        Assert::assertSame(['c1', 'c2', 'c3'], $action->ran);
        Assert::assertSame(1, $action->plans);
        Assert::assertTrue($resumed->id->equals($stopped->id));
        Assert::assertSame(OperationState::Completed, $resumed->state);
        Assert::assertSame(['c1', 'c2', 'c3'], $resumed->completedNames());
    }

    #[Test]
    public function a_resumed_operation_keeps_the_plan_it_started_with(): void
    {
        $runner = $this->operationRunner();
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2'], failOnceAt: 'c2');
        $request = new OperationRequest($action, new OperationKey('run-1'));

        try {
            $runner->run($request);
        } catch (RuntimeException) {
        }

        $action->replan(['x1', 'x2', 'x3']);
        $resumed = $runner->run($request);

        Assert::assertSame(['c1', 'c2'], $action->ran);
        Assert::assertSame(['c1', 'c2'], $resumed->completedNames());
    }

    #[Test]
    public function running_a_completed_operation_again_runs_no_chunk(): void
    {
        $runner = $this->operationRunner();
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2']);
        $request = new OperationRequest($action, new OperationKey('run-1'));

        $first = $runner->run($request);
        $again = $runner->run($request);

        Assert::assertSame(['c1', 'c2'], $action->ran);
        Assert::assertSame(1, $action->plans);
        $this->assertSameProgress($first, $again);
    }

    #[Test]
    public function another_key_or_another_kind_starts_another_operation(): void
    {
        $runner = $this->operationRunner();
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1']);
        $other = new RecordingChunkedAction('scratch.import', ['c1']);

        $first = $runner->run(new OperationRequest($action, new OperationKey('run-1')));
        $second = $runner->run(new OperationRequest($action, new OperationKey('run-2')));
        $third = $runner->run(new OperationRequest($other, new OperationKey('run-1')));

        Assert::assertSame(['c1', 'c1'], $action->ran);
        Assert::assertSame(['c1'], $other->ran);
        Assert::assertFalse($first->id->equals($second->id));
        Assert::assertFalse($first->id->equals($third->id));
        Assert::assertFalse($second->id->equals($third->id));
        Assert::assertSame('scratch.import', $third->kind->value);
        Assert::assertTrue($runner->find(new OperationKind('scratch.rebuild'), new OperationKey('run-2'))?->id->equals($second->id));
    }

    #[Test]
    public function after_a_failed_operation_the_key_starts_a_new_operation_with_a_new_plan(): void
    {
        $runner = $this->operationRunner();
        $action = new RecordingChunkedAction('scratch.rebuild', ['c1', 'c2'], failOnceAt: 'c2');
        $request = new OperationRequest($action, new OperationKey('run-1'));

        try {
            $runner->run($request);
        } catch (RuntimeException) {
        }

        $stopped = $runner->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'));
        Assert::assertNotNull($stopped);
        $this->failOperation($stopped->id);

        $failed = $runner->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1'));
        Assert::assertNotNull($failed);
        Assert::assertSame(OperationState::Failed, $failed->state);
        Assert::assertTrue($failed->id->equals($stopped->id));

        $action->replan(['n1', 'n2']);
        $fresh = $runner->run($request);

        Assert::assertFalse($fresh->id->equals($stopped->id));
        Assert::assertSame(['c1', 'n1', 'n2'], $action->ran);
        Assert::assertSame(['n1', 'n2'], $fresh->completedNames());
        Assert::assertSame(OperationState::Completed, $fresh->state);
        $this->assertSameProgress($fresh, $runner->find(new OperationKind('scratch.rebuild'), new OperationKey('run-1')));
    }

    #[Test]
    public function an_empty_plan_completes_without_running_a_chunk(): void
    {
        $action = new RecordingChunkedAction('scratch.rebuild', []);

        $progress = $this->operationRunner()->run(new OperationRequest($action, new OperationKey('run-1')));

        Assert::assertSame([], $action->ran);
        Assert::assertSame(OperationState::Completed, $progress->state);
        Assert::assertSame([], $progress->completedNames());
    }

    #[Test]
    public function find_returns_null_for_a_kind_and_key_without_an_operation(): void
    {
        $this->operationRunner()->run(new OperationRequest(new RecordingChunkedAction('scratch.rebuild', ['c1']), new OperationKey('run-1')));

        Assert::assertNull($this->operationRunner()->find(new OperationKind('scratch.rebuild'), new OperationKey('run-2')));
        Assert::assertNull($this->operationRunner()->find(new OperationKind('scratch.other'), new OperationKey('run-1')));
    }

    private function assertSameProgress(OperationProgress $expected, ?OperationProgress $actual): void
    {
        Assert::assertNotNull($actual);
        Assert::assertTrue($expected->id->equals($actual->id));
        Assert::assertSame($expected->state, $actual->state);
        Assert::assertSame($expected->completedNames(), $actual->completedNames());
        Assert::assertSame($expected->remainingNames(), $actual->remainingNames());
    }
}
