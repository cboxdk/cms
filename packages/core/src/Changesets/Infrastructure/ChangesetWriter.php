<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Changesets\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\ReasonText;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Changesets\Domain\Dto\ChangesetRecord;
use Cbox\Cms\Core\Partitions\Boundary\MissingPartition;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * Writes the record of a changeset in the command transaction (PRD 5.5, 6.1, 6.2 phase 7, 12.12),
 * as the app role, under the actor context the command transaction set:
 *
 * - `changeset_register`: the id and the retention class.
 * - `changesets`: the metadata, the command's name and version, the actor, the issuer kind and
 *   the surface, the reason code, the idempotency key, the correlation id, the provenance and the
 *   format version FORMAT_VERSION. The commit position, `xid`, is the column's default, the
 *   transaction's pg_current_xact_id().
 * - `changeset_principals`: the on-behalf-of chain in order, from position 1.
 * - `changeset_reason_texts`: the reason's free text, when there is one, classified
 *   REASON_CLASSIFICATION, because it can name people; never in the audit.
 * - `audit`: one row, with the actor, the command, the issuer kind, the surface, the reason code
 *   and the aggregates the changeset changes as their keys. It has no legal basis, because no
 *   command of the kernel carries one yet, and no text.
 *
 * It runs on the caller's connection, the default connection unless one is named, inside the
 * caller's open transaction, and never begins, commits or rolls back one (GUARDRAILS 4.1). Without
 * an open transaction it throws TransactionRequired before any statement. A changeset time that no
 * partition of `changesets`, `changeset_principals` or `audit` covers throws PartitionMissing, and
 * Postgres has then failed the caller's transaction: the caller rolls back.
 */
#[Internal]
final readonly class ChangesetWriter
{
    /** The format version of the stored changeset (PRD 3.3). */
    public const int FORMAT_VERSION = 1;

    /**
     * The metadata row. Postgres builds the provenance's JSON columns from text arrays, the
     * parameters as an object of names to values and the sources as an array, or null when there
     * are none; no JSON is written in PHP.
     */
    public const string INSERT_CHANGESET = <<<'SQL'
        insert into changesets (
            changeset_id, command, command_version, actor_id, issuer_kind, surface, reason_code,
            idempotency_key, correlation_id, provenance_model, provenance_model_version,
            provenance_parameters, provenance_prompt, provenance_sources, format_version, created_at
        ) values (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            case when ?::boolean then jsonb_object(?::text[], ?::text[]) end,
            ?,
            case when ?::boolean then to_jsonb(?::text[]) end,
            ?, ?::timestamptz
        )
        SQL;

    /** The classification of a reason's free text (PRD 6.1, 12.12). */
    public const ClassificationAccess REASON_CLASSIFICATION = ClassificationAccess::Personal;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * @throws TransactionRequired when the connection has no transaction open; nothing is written
     * @throws PartitionMissing when no partition covers the changeset's id
     */
    public function write(ChangesetRecord $record): void
    {
        $db = $this->connections->connection($this->connection);

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forChangeset();
        }

        try {
            $this->insert($db, $record);
        } catch (QueryException $exception) {
            throw MissingPartition::of($exception) ?? $exception;
        }
    }

    private function insert(ConnectionInterface $db, ChangesetRecord $record): void
    {
        $id = $record->id->toString();
        $envelope = $record->envelope;
        $at = $this->timestamp($record->at);
        $actor = $envelope->actor->toString();
        $reason = $envelope->auditReason()?->value;
        $provenance = $envelope->provenance;

        $db->table('changeset_register')->insert([
            'changeset_id' => $id,
            'retention_class' => $record->retentionClass->value,
        ]);

        $names = array_map(static fn (ModelParameter $parameter): string => $parameter->name, $provenance->parameters);
        $values = array_map(static fn (ModelParameter $parameter): string => $parameter->value, $provenance->parameters);
        $sources = array_map(static fn (SourceReference $source): string => $source->value, $provenance->sources);

        $db->statement(self::INSERT_CHANGESET, [
            $id,
            $record->command->value,
            $record->commandVersion,
            $actor,
            $envelope->issuerKind->value,
            $envelope->surface->value,
            $reason,
            $envelope->idempotencyKey->value,
            $envelope->correlationId->value,
            $provenance->model?->name,
            $provenance->model?->version,
            $names === [] ? 'false' : 'true',
            $this->quotedArray($names),
            $this->quotedArray($values),
            $provenance->prompt?->value,
            $sources === [] ? 'false' : 'true',
            $this->quotedArray($sources),
            self::FORMAT_VERSION,
            $at,
        ]);

        $principals = [];

        foreach ($envelope->onBehalfOf->chain as $index => $principal) {
            $principals[] = ['changeset_id' => $id, 'position' => $index + 1, 'actor_id' => $principal->toString()];
        }

        if ($principals !== []) {
            $db->table('changeset_principals')->insert($principals);
        }

        $text = $envelope->reason?->text;

        if ($text instanceof ReasonText) {
            $db->table('changeset_reason_texts')->insert([
                'changeset_id' => $id,
                'classification' => self::REASON_CLASSIFICATION->value,
                'text' => $text->classifiedContent(),
                'created_at' => $at,
            ]);
        }

        $db->table('audit')->insert([
            'changeset_id' => $id,
            'actor_id' => $actor,
            'command' => $record->command->value,
            'command_version' => $record->commandVersion,
            'issuer_kind' => $envelope->issuerKind->value,
            'surface' => $envelope->surface->value,
            'reason_code' => $reason,
            'legal_basis' => null,
            'aggregates' => $this->textArray(array_map(static fn (AggregateRef $aggregate): string => $aggregate->aggregateKey(), $record->aggregates)),
            'created_at' => $at,
        ]);
    }

    /**
     * A text[] literal of any text: every element double-quoted, with its backslashes and double
     * quotes escaped.
     *
     * @param  list<string>  $values
     */
    private function quotedArray(array $values): string
    {
        return '{'.implode(',', array_map(
            static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        )).'}';
    }

    /**
     * A text[] literal. Aggregate keys hold only letters, digits, underscores, hyphens and colons,
     * so no element needs quoting.
     *
     * @param  list<string>  $values
     */
    private function textArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    private function timestamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
