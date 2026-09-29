<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;

/**
 * Writes the read audit (PRD 12.2, 12.12): which actor read which fields of which entries, by id
 * and field address, never the values. It writes synchronously inside the caller's open read
 * transaction, under the actor context the read set, so the audit commits with the answer and a
 * read whose audit cannot be written is not answered.
 */
#[Internal]
interface ReadAudit
{
    /**
     * @throws TransactionRequired when the caller has no transaction open; nothing is written
     * @throws PartitionMissing when no partition covers the time of the read
     */
    public function record(ReadAuditRecord $record): void;
}
