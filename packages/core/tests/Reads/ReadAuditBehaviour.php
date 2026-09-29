<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ReadAudit does (PRD 12.12), held against the fake the pipeline's action tests use and
 * the Postgres read audit: a record is written only inside the caller's transaction, stays when
 * the transaction commits with one entry per audited read, and is gone when it rolls back.
 */
trait ReadAuditBehaviour
{
    abstract protected function readAudit(): ReadAudit;

    /**
     * A record of the reads of these entries, by an actor the implementation may write for.
     */
    abstract protected function auditRecord(string ...$entries): ReadAuditRecord;

    /**
     * Opens a transaction the read audit writes in, as the read's actor.
     */
    abstract protected function begin(): void;

    abstract protected function commit(): void;

    abstract protected function rollBack(): void;

    /**
     * The entries of the committed records, one per audited read, sorted.
     *
     * @return list<string>
     */
    abstract protected function auditedEntries(): array;

    #[Test]
    public function it_keeps_every_audited_read_of_a_committed_record(): void
    {
        $this->begin();
        $this->readAudit()->record($this->auditRecord('01936f5e-8a2b-7c3d-9e4f-0000000000e2', '01936f5e-8a2b-7c3d-9e4f-0000000000e1'));
        $this->commit();

        Assert::assertSame(['01936f5e-8a2b-7c3d-9e4f-0000000000e1', '01936f5e-8a2b-7c3d-9e4f-0000000000e2'], $this->auditedEntries());
    }

    #[Test]
    public function it_keeps_nothing_of_a_record_whose_transaction_rolls_back(): void
    {
        $this->begin();
        $this->readAudit()->record($this->auditRecord('01936f5e-8a2b-7c3d-9e4f-0000000000e1'));
        $this->rollBack();

        Assert::assertSame([], $this->auditedEntries());
    }

    #[Test]
    public function it_refuses_to_write_outside_a_transaction(): void
    {
        try {
            $this->readAudit()->record($this->auditRecord('01936f5e-8a2b-7c3d-9e4f-0000000000e1'));
            Assert::fail('The read audit was written outside a transaction.');
        } catch (TransactionRequired $required) {
            Assert::assertStringContainsString('read audit is written inside the read transaction', $required->getMessage());
        }

        Assert::assertSame([], $this->auditedEntries());
    }
}
