<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Fails a test that nests transactions (PRD 4.2, GUARDRAILS 4.1).
 *
 * Laravel counts transactions per connection. A begin at level 1 or higher does not start a
 * transaction; it issues `SAVEPOINT trans<n>`. More than 64 subtransactions make every replica
 * slow, so savepoints are forbidden in actions and jobs, and the Postgres suite forbids them
 * everywhere. The guard listens to TransactionBeginning, which Laravel fires after it has raised
 * the level, and fails when a connection's transactionLevel() is above 1.
 *
 * It throws at the nested begin, so the failure points at the caller. Code that catches the
 * throwable does not hide it: the violation is also recorded, and assertClean() fails the test
 * in tear-down.
 */
#[Experimental]
final class NestedTransactionGuard
{
    /** @var list<string> */
    private array $violations = [];

    public function install(Dispatcher $events): void
    {
        $events->listen(TransactionBeginning::class, $this->handle(...));
    }

    public function handle(TransactionBeginning $event): void
    {
        $level = $event->connection->transactionLevel();

        if ($level <= 1) {
            return;
        }

        $message = sprintf(
            'Nested transaction on connection [%s]: transactionLevel() is %d, so Laravel issued SAVEPOINT trans%d.'
            .' Savepoints and nested transactions are forbidden (PRD 4.2, GUARDRAILS 4.1).'
            .' Open one transaction per command and let the kernel own the commit.',
            $event->connectionName,
            $level,
            $level,
        );

        $this->violations[] = $message;

        throw new AssertionFailedError($message);
    }

    /**
     * Returns the recorded violations and forgets them. For tests of the guard itself.
     *
     * @return list<string>
     */
    public function pullViolations(): array
    {
        $violations = $this->violations;
        $this->violations = [];

        return $violations;
    }

    public function assertClean(): void
    {
        if ($this->violations !== []) {
            throw new AssertionFailedError(implode("\n", $this->pullViolations()));
        }
    }
}
