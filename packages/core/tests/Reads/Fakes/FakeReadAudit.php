<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;
use LogicException;
use Override;

/**
 * The read audit in memory, as a session of the fake read transaction: a record is kept with the
 * transaction it was written in, and stays only when that transaction commits. ReadAuditBehaviour
 * holds it to PostgresReadAudit.
 */
final class FakeReadAudit implements ReadAudit, TransactionalSession
{
    /** @var list<ReadAuditRecord> the records of committed transactions, in order */
    public private(set) array $records = [];

    /** @var list<ReadAuditRecord>|null the records of the open transaction; null without one */
    private ?array $pending = null;

    #[Override]
    public function record(ReadAuditRecord $record): void
    {
        if ($this->pending === null) {
            throw TransactionRequired::forReadAudit();
        }

        $this->pending[] = $record;
    }

    #[Override]
    public function begin(): void
    {
        if ($this->pending !== null) {
            throw new LogicException('The fake read audit already has a transaction open.');
        }

        $this->pending = [];
    }

    #[Override]
    public function commit(): void
    {
        array_push($this->records, ...$this->pending ?? throw new LogicException('The fake read audit has no transaction to commit.'));
        $this->pending = null;
    }

    #[Override]
    public function rollBack(): void
    {
        $this->pending = null;
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->pending !== null;
    }
}
