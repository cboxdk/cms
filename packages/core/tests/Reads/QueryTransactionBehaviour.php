<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\QueryTransactionOpen;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCount;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What every QueryTransaction does (PRD 6.2), held against the fake the pipeline's action tests use
 * and the connection adapter on real Postgres: it runs the work in one transaction that the read
 * audit writes in, commits an answered result, rolls back a rejected one and work that throws,
 * gives the read's position only while it runs, and never nests.
 *
 * What the work wrote is observed through the read audit: a record written in the transaction is
 * there afterwards when the transaction was committed, and gone when it was rolled back.
 */
trait QueryTransactionBehaviour
{
    /**
     * The implementation under test, on the connection the read audit of recordInside() writes on.
     */
    abstract protected function queryTransaction(): QueryTransaction;

    /**
     * Writes one record to the read audit in the running transaction.
     */
    abstract protected function recordInside(): void;

    /**
     * How many records the read audit holds, as committed.
     */
    abstract protected function recorded(): int;

    /**
     * Opens a transaction on the connection outside the implementation.
     */
    abstract protected function openOutside(): void;

    abstract protected function closeOutside(): void;

    #[Test]
    public function it_commits_an_answered_result(): void
    {
        $this->queryTransaction()->run(function (): QueryResult {
            $this->recordInside();

            return $this->answered();
        });

        Assert::assertSame(1, $this->recorded());
    }

    #[Test]
    public function it_rolls_back_a_rejected_result(): void
    {
        $this->queryTransaction()->run(function (): QueryResult {
            $this->recordInside();

            return $this->rejected();
        });

        Assert::assertSame(0, $this->recorded());
    }

    #[Test]
    public function it_returns_the_work_s_result(): void
    {
        $result = $this->rejected();

        Assert::assertSame($result, $this->queryTransaction()->run(static fn (): QueryResult => $result));
    }

    #[Test]
    public function it_rolls_back_work_that_throws_and_throws_the_same_exception(): void
    {
        $failure = new RuntimeException('The work failed.');
        $thrown = null;

        try {
            $this->queryTransaction()->run(function () use ($failure): never {
                $this->recordInside();

                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        Assert::assertSame($failure, $thrown);
        Assert::assertSame(0, $this->recorded());
    }

    #[Test]
    public function it_gives_the_read_s_position_while_it_runs_and_none_outside(): void
    {
        $transaction = $this->queryTransaction();
        $positions = [];

        $transaction->run(function () use ($transaction, &$positions): QueryResult {
            $positions[] = $transaction->position();
            $positions[] = $transaction->position();

            return $this->answered();
        });

        Assert::assertCount(2, $positions);
        Assert::assertInstanceOf(CommitPosition::class, $positions[0]);
        Assert::assertTrue($positions[0]->equals($positions[1]));

        try {
            $transaction->position();
            Assert::fail('A position was given outside a read transaction.');
        } catch (TransactionRequired $required) {
            Assert::assertStringContainsString('position of a read', $required->getMessage());
        }
    }

    #[Test]
    public function it_refuses_to_run_inside_an_open_transaction(): void
    {
        $ran = false;
        $this->openOutside();

        try {
            $this->queryTransaction()->run(function () use (&$ran): QueryResult {
                $ran = true;

                return $this->answered();
            });
            Assert::fail('A read transaction ran inside an open transaction.');
        } catch (QueryTransactionOpen $exception) {
            Assert::assertStringContainsString('already has a transaction open', $exception->getMessage());
        } finally {
            $this->closeOutside();
        }

        Assert::assertFalse($ran);
    }

    private function answered(): QueryResult
    {
        return QueryResult::answered(new ProbeCount(0), [], new CommitPosition('4827'));
    }

    private function rejected(): QueryResult
    {
        return QueryResult::rejected(new CatalogError(ErrorCode::Unauthorized, null, 'The behaviour refuses the read.'));
    }
}
