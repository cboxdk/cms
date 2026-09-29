<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What every CommandTransaction does (PRD 6.2 phase 7), held against the fake the pipeline's
 * action tests use and the connection adapter on real Postgres: it runs the work in one
 * transaction that an idempotency store can claim in, commits a result that committed a changeset,
 * rolls back every other result and a work that throws, and never nests.
 *
 * What the work wrote is observed through the idempotency store: a key the work completed replays
 * in the next transaction when it was committed, and is fresh again when it was rolled back.
 */
trait CommandTransactionBehaviour
{
    /**
     * The implementation under test, on the connection the store of keys() runs on.
     */
    abstract protected function commandTransaction(): CommandTransaction;

    /**
     * An idempotency store on the transaction's connection, at clock().
     */
    abstract protected function keys(): IdempotencyStore;

    abstract protected function clock(): Clock;

    /**
     * Opens a transaction on the connection outside the implementation.
     */
    abstract protected function openOutside(): void;

    abstract protected function closeOutside(): void;

    #[Test]
    public function it_commits_a_result_that_committed_a_changeset(): void
    {
        $changeset = $this->completeIn(fn (ChangesetId $id): WriteResult => $this->committed($id));

        $claim = $this->claimAfter();

        Assert::assertInstanceOf(Replay::class, $claim);
        Assert::assertTrue($claim->changesetId->equals($changeset));
    }

    #[Test]
    public function it_rolls_back_a_rejected_result(): void
    {
        $this->completeIn(fn (ChangesetId $id): WriteResult => $this->rejected());

        Assert::assertInstanceOf(Fresh::class, $this->claimAfter());
    }

    #[Test]
    public function it_rolls_back_a_dry_run(): void
    {
        $this->completeIn(static fn (ChangesetId $id): WriteResult => WriteResult::dryRun(
            Receipt::dryRun(WaitLevel::Commit, RetentionClass::Standard),
            DryRunReport::of(new Plan, new ReadVersions),
        ));

        Assert::assertInstanceOf(Fresh::class, $this->claimAfter());
    }

    #[Test]
    public function it_returns_the_work_s_result(): void
    {
        $result = $this->rejected();

        Assert::assertSame($result, $this->commandTransaction()->run(static fn (): WriteResult => $result));
    }

    #[Test]
    public function it_rolls_back_work_that_throws_and_throws_the_same_exception(): void
    {
        $failure = new RuntimeException('The work failed.');
        $thrown = null;

        try {
            $this->completeIn(static fn (ChangesetId $id): never => throw $failure);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        Assert::assertSame($failure, $thrown);
        Assert::assertInstanceOf(Fresh::class, $this->claimAfter());
    }

    #[Test]
    public function it_refuses_to_run_inside_an_open_transaction(): void
    {
        $ran = false;
        $this->openOutside();

        try {
            $this->commandTransaction()->run(function () use (&$ran): WriteResult {
                $ran = true;

                return $this->rejected();
            });
            Assert::fail('A command transaction ran inside an open transaction.');
        } catch (CommandTransactionOpen $exception) {
            Assert::assertStringContainsString('already has a transaction open', $exception->getMessage());
        } finally {
            $this->closeOutside();
        }

        Assert::assertFalse($ran);
    }

    /**
     * Runs the work in a command transaction after claiming and completing the key there with a
     * new changeset, and returns the changeset.
     *
     * @param  Closure(ChangesetId): WriteResult  $result
     */
    private function completeIn(Closure $result): ChangesetId
    {
        $changeset = new ChangesetId(new FakeIdGenerator(clock: $this->clock())->next());

        $this->commandTransaction()->run(function () use ($changeset, $result): WriteResult {
            $claim = $this->claim();
            Assert::assertInstanceOf(Fresh::class, $claim);
            $this->keys()->complete($claim->token, $changeset);

            return $result($changeset);
        });

        return $changeset;
    }

    /**
     * What a claim on the key finds in a command transaction of its own, which is rolled back.
     */
    private function claimAfter(): ClaimResult
    {
        $claim = null;

        $this->commandTransaction()->run(function () use (&$claim): WriteResult {
            $claim = $this->claim();

            return $this->rejected();
        });

        Assert::assertInstanceOf(ClaimResult::class, $claim);

        return $claim;
    }

    private function claim(): ClaimResult
    {
        return $this->keys()->claim(
            IdempotencyScope::forActor(new PrincipalId('behaviour-actor'), new CommandName('probe.rename')),
            new IdempotencyKey('behaviour-key'),
            ContentHash::of('behaviour-content'),
            WaitBudget::none(),
        );
    }

    private function committed(ChangesetId $changeset): WriteResult
    {
        return WriteResult::committed(Receipt::committed($changeset, WaitLevel::Commit, RetentionClass::Standard, new CommitPosition('4827')));
    }

    private function rejected(): WriteResult
    {
        return WriteResult::rejected(
            Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard),
            new CatalogError(ErrorCode::Unauthorized, null, 'The behaviour test rejects this call.'),
        );
    }
}
