<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\QueryTransactionOpen;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;
use Closure;
use Override;
use Throwable;

/**
 * One read transaction over fake sessions, such as the fake read audit and the fake access
 * resolver the pipeline's action tests use: it begins them all, runs the work, and commits them
 * all when the result was answered, or rolls them all back otherwise and when the work throws. Its
 * position is the one it was given, while it runs. It counts what it did.
 * QueryTransactionBehaviour holds it to ConnectionQueryTransaction.
 */
final class FakeQueryTransaction implements QueryTransaction
{
    public const string CONNECTION = 'fake';

    /** @var list<TransactionalSession> */
    private readonly array $sessions;

    private bool $running = false;

    public int $commits = 0;

    public int $rollBacks = 0;

    public function __construct(
        private readonly CommitPosition $position = new CommitPosition('4827'),
        TransactionalSession ...$sessions,
    ) {
        $this->sessions = array_values($sessions);
    }

    #[Override]
    public function run(Closure $work): QueryResult
    {
        if ($this->running || array_any($this->sessions, static fn (TransactionalSession $session): bool => $session->inTransaction())) {
            throw QueryTransactionOpen::onConnection(self::CONNECTION);
        }

        foreach ($this->sessions as $session) {
            $session->begin();
        }

        $this->running = true;

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->end(false);

            throw $exception;
        }

        $this->end($result->isAnswered());

        return $result;
    }

    #[Override]
    public function position(): CommitPosition
    {
        return $this->running ? $this->position : throw TransactionRequired::forReadPosition();
    }

    private function end(bool $commit): void
    {
        $this->running = false;

        foreach ($this->sessions as $session) {
            $commit ? $session->commit() : $session->rollBack();
        }

        $commit ? $this->commits++ : $this->rollBacks++;
    }
}
