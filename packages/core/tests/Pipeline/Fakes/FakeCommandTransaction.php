<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;
use Closure;
use Override;
use Throwable;

/**
 * One command transaction over the testkit's fake sessions, such as the fake idempotency and
 * receipt sessions the pipeline's stores run on: it begins them all, runs the work, and commits
 * them all when the result committed a changeset, or rolls them all back otherwise and when the
 * work throws. It counts what it did and records the access context of each run.
 * CommandTransactionBehaviour holds it to ConnectionCommandTransaction.
 */
final class FakeCommandTransaction implements CommandTransaction
{
    public const string CONNECTION = 'fake';

    /** @var list<TransactionalSession> */
    private readonly array $sessions;

    public int $commits = 0;

    public int $rollBacks = 0;

    /** @var list<AccessContext> the access context of each run, in order */
    public array $access = [];

    public function __construct(TransactionalSession ...$sessions)
    {
        $this->sessions = array_values($sessions);
    }

    #[Override]
    public function run(AccessContext $access, Closure $work): WriteResult
    {
        foreach ($this->sessions as $session) {
            if ($session->inTransaction()) {
                throw CommandTransactionOpen::onConnection(self::CONNECTION);
            }
        }

        foreach ($this->sessions as $session) {
            $session->begin();
        }

        $this->access[] = $access;

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        if (! $result->receipt->isCommitted()) {
            $this->rollBack();

            return $result;
        }

        foreach ($this->sessions as $session) {
            $session->commit();
        }

        $this->commits++;

        return $result;
    }

    private function rollBack(): void
    {
        foreach ($this->sessions as $session) {
            $session->rollBack();
        }

        $this->rollBacks++;
    }
}
