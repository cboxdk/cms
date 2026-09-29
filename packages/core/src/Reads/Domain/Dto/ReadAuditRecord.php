<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;

/**
 * The read audit of one read (PRD 12.12): the actor who read, the query by name and version, the
 * read's position, and the entries whose fields required it. Only an actor reads a field that
 * requires the audit, because the anonymous principal reads only public fields.
 */
#[Internal]
final readonly class ReadAuditRecord
{
    /** @var non-empty-list<AuditedRead> */
    public array $reads;

    /**
     * @param  list<AuditedRead>  $reads
     *
     * @throws InvalidQueryCall when no entry is given
     */
    public function __construct(
        public ActorId $actor,
        public CommandName $query,
        public int $version,
        public CommitPosition $position,
        array $reads,
    ) {
        if ($reads === []) {
            throw InvalidQueryCall::auditWithoutReads($query);
        }

        $this->reads = $reads;
    }
}
