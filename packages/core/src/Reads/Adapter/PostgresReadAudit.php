<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Override;

/**
 * The read audit on Postgres (PRD 12.12): one row in `read_audit` per entry a read returned
 * fields of that require it, all with the read's id, a UUIDv7 from the IdGenerator at the Clock's
 * time, which is the table's partition key. It writes on the default connection, or the one named,
 * inside the caller's read transaction and under its actor context: the table's policy lets the
 * app role insert only rows of the context's actor, and read none.
 *
 * Without an open transaction it throws TransactionRequired before any statement. A read whose day
 * no partition covers throws PartitionMissing and writes nothing; the read is not answered.
 */
#[Internal]
final readonly class PostgresReadAudit implements ReadAudit
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function record(ReadAuditRecord $record): void
    {
        $db = $this->db();

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forReadAudit();
        }

        $read = $this->ids->next()->value;
        $createdAt = $this->clock->now()->format('Y-m-d H:i:s.uP');

        try {
            $db->table('read_audit')->insert(array_map(static fn (AuditedRead $audited): array => [
                'read_id' => $read,
                'entry_id' => $audited->entry->toString(),
                'actor_id' => $record->actor->toString(),
                'query' => $record->query->value,
                'query_version' => $record->version,
                'classification' => $audited->classification->value,
                'fields' => '{'.implode(',', $audited->fields).'}',
                'read_position' => $record->position->value,
                'created_at' => $createdAt,
            ], $record->reads));
        } catch (QueryException $exception) {
            throw MissingPartitionMapper::map($exception);
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
