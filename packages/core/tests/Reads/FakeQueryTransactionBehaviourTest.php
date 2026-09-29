<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * QueryTransactionBehaviour against the fake the pipeline's action tests use, over the fake read
 * audit.
 */
final class FakeQueryTransactionBehaviourTest extends TestCase
{
    use QueryTransactionBehaviour;

    private ?FakeReadAudit $audit = null;

    #[Override]
    protected function queryTransaction(): QueryTransaction
    {
        return new FakeQueryTransaction(new CommitPosition('4827'), $this->audit());
    }

    #[Override]
    protected function recordInside(): void
    {
        $this->audit()->record(new ReadAuditRecord(
            ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c1'),
            new CommandName('probe.read'),
            2,
            new CommitPosition('4827'),
            [new AuditedRead(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'), ['diagnosis'], ClassificationAccess::Sensitive)],
        ));
    }

    #[Override]
    protected function recorded(): int
    {
        return count($this->audit()->records);
    }

    #[Override]
    protected function openOutside(): void
    {
        $this->audit()->begin();
    }

    #[Override]
    protected function closeOutside(): void
    {
        $this->audit()->rollBack();
    }

    private function audit(): FakeReadAudit
    {
        return $this->audit ??= new FakeReadAudit;
    }
}
