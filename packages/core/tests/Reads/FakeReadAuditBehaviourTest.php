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
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ReadAuditBehaviour against the fake the pipeline's action tests use.
 */
final class FakeReadAuditBehaviourTest extends TestCase
{
    use ReadAuditBehaviour;

    private ?FakeReadAudit $audit = null;

    #[Override]
    protected function readAudit(): ReadAudit
    {
        return $this->audit();
    }

    #[Override]
    protected function auditRecord(string ...$entries): ReadAuditRecord
    {
        return new ReadAuditRecord(
            ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c1'),
            new CommandName('probe.read'),
            2,
            new CommitPosition('4827'),
            array_map(static fn (string $entry): AuditedRead => new AuditedRead(EntryId::fromString($entry), ['diagnosis'], ClassificationAccess::Sensitive), array_values($entries)),
        );
    }

    #[Override]
    protected function begin(): void
    {
        $this->audit()->begin();
    }

    #[Override]
    protected function commit(): void
    {
        $this->audit()->commit();
    }

    #[Override]
    protected function rollBack(): void
    {
        $this->audit()->rollBack();
    }

    #[Override]
    protected function auditedEntries(): array
    {
        $entries = [];

        foreach ($this->audit()->records as $record) {
            foreach ($record->reads as $read) {
                $entries[] = $read->entry->toString();
            }
        }

        sort($entries);

        return $entries;
    }

    private function audit(): FakeReadAudit
    {
        return $this->audit ??= new FakeReadAudit;
    }
}
