<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\TypeRows;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;
use Throwable;
use UnexpectedValueException;

/**
 * The rebuild of a type's read model on Postgres (PRD 4.1, 11.6, invariant 22), as the app role on
 * the default connection, or the one named, under the rebuild's access context, so row level
 * security decides which entries it reads and writes (PRD 5.10).
 *
 * Every transaction begins with READ_COMMITTED and TIMEOUT, which turns transaction_timeout off and
 * on again at TRANSACTION_MILLISECONDS, so Postgres ends a transaction that runs past 2 seconds
 * (GUARDRAILS 4.1) whatever the role's own limit is, and then sets the context with ActorContext.
 *
 * plan() reads the type's entry ids in id order, PLAN, one range of at most the chunk size per
 * transaction, from the index on (type_id, id).
 *
 * rebuild() is one transaction for one range. HEADS reads each variant head of the range's entries
 * with the payloads it rebuilds from, and takes FOR SHARE on the heads, so a command that changes
 * one of them waits until the chunk commits, and the chunk waits for one that changes it first and
 * then reads what it committed. For a type with full history, the draft is the head's draft
 * revision and the released content its published revision while its release state is released;
 * for a type whose history is audit-only or none, the head snapshot is the content. Every payload
 * must be at the type's current schema version and payload format, or the chunk throws before it
 * writes; upcasters come with B3. Then it removes every row of those entries from the type table,
 * by their ids, so a row an entry should not have is gone and an entry created meanwhile keeps its
 * row, and writes each variant's rows through TypeRows, the writer of the entry commands: the
 * released row with release(), for a type with stages, then the draft with write(), which writes
 * the released row of a type with stages none and otherwise a draft row that stays only where it
 * differs from the released one. The rows are therefore the ones the commands left.
 */
#[Internal]
final readonly class PostgresReadModelStore implements ReadModelStore
{
    /** The limit of each transaction (GUARDRAILS 4.1). */
    public const int TRANSACTION_MILLISECONDS = 2_000;

    public const string READ_COMMITTED = 'set transaction isolation level read committed';

    /** The time limit of the transaction, from this statement on, as SET LOCAL. */
    public const string TIMEOUT = "select set_config('transaction_timeout', '0', true), set_config('transaction_timeout', ?, true)";

    /** The next ids of the type's entries after an id. */
    public const string PLAN = 'select id::text as id from entries where type_id = ?::uuid and id > ?::uuid order by id limit ?';

    /** Each variant head of the range's entries, with the payloads a rebuild reads, locked. */
    public const string HEADS = <<<'SQL'
        select h.entry_id::text as entry_id, h.variant,
            d.schema_version as draft_schema, dp.format_version as draft_format, dp.content::text as draft_content,
            p.schema_version as released_schema, pp.format_version as released_format, pp.content::text as released_content,
            s.schema_version as snapshot_schema, s.format_version as snapshot_format, s.content::text as snapshot_content
        from entries as e
        join variant_heads as h on h.entry_id = e.id
        left join revisions as d on d.revision_id = h.draft_revision_id
        left join revision_payloads as dp on dp.revision_id = d.revision_id and dp.kind = d.kind
        left join revisions as p on p.revision_id = h.published_revision_id and h.release_state = 'released'
        left join revision_payloads as pp on pp.revision_id = p.revision_id and pp.kind = p.kind
        left join head_snapshots as s on s.entry_id = h.entry_id and s.variant = h.variant
        where e.type_id = ?::uuid and e.id >= ?::uuid and e.id <= ?::uuid
        order by h.entry_id, h.variant
        for share of h
        SQL;

    /** Sorts before every uuid, so the first range starts after it. */
    private const string BEFORE_EVERY_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * @throws InvalidArgumentException when the chunk size is below 1
     * @throws LogicException when the connection is already in a transaction
     */
    #[Override]
    public function plan(TypeDefinition $type, AccessContext $access, int $chunkSize): array
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException(sprintf('A range of a rebuild holds at least one entry, got a chunk size of %d.', $chunkSize));
        }

        $ranges = [];
        $after = self::BEFORE_EVERY_ID;

        do {
            $ids = $this->transaction($access, fn (ConnectionInterface $db): array => $this->ids($db, $type, $after, $chunkSize));

            if ($ids === []) {
                break;
            }

            $after = $ids[count($ids) - 1];
            $ranges[] = new EntryRange(EntryId::fromString($ids[0]), EntryId::fromString($after));
        } while (count($ids) === $chunkSize);

        return $ranges;
    }

    /**
     * @throws LogicException when the connection is already in a transaction, or a head has no payload to rebuild from
     */
    #[Override]
    public function rebuild(TypeDefinition $type, AccessContext $access, EntryRange $range): ChunkResult
    {
        $started = hrtime(true);

        [$entries, $variants] = $this->transaction($access, fn (ConnectionInterface $db): array => $this->rewrite($db, $type, $range));

        return new ChunkResult($range->chunk(), $entries, $variants, intdiv(hrtime(true) - $started, 1_000_000));
    }

    /**
     * @return list<string>
     */
    private function ids(ConnectionInterface $db, TypeDefinition $type, string $after, int $limit): array
    {
        $ids = [];

        foreach ($db->select(self::PLAN, [$type->id->toString(), $after, $limit], false) as $row) {
            $ids[] = $this->text($row, 'id');
        }

        return $ids;
    }

    /**
     * Rewrites the rows of the range's entries; the entries and the variant heads it rebuilt.
     *
     * @return array{int, int}
     */
    private function rewrite(ConnectionInterface $db, TypeDefinition $type, EntryRange $range): array
    {
        $heads = [];
        $entries = [];

        foreach ($db->select(self::HEADS, [$type->id->toString(), $range->first->toString(), $range->last->toString()], false) as $row) {
            if (! is_object($row)) {
                throw new UnexpectedValueException(sprintf('A variant head is a row, got %s.', get_debug_type($row)));
            }

            $entry = EntryId::fromString($this->text($row, 'entry_id'));
            $variant = VariantKey::fromString($this->text($row, 'variant'));
            $draft = $type->capabilities->history === History::Full
                ? $this->payload($type, $entry, $variant, $row, 'draft') ?? throw new LogicException(sprintf('The variant %s of the entry %s has no draft revision to rebuild from.', $variant->value, $entry->toString()))
                : $this->payload($type, $entry, $variant, $row, 'snapshot') ?? throw new LogicException(sprintf('The variant %s of the entry %s has no head snapshot to rebuild from.', $variant->value, $entry->toString()));
            $released = $type->capabilities->stages === Stages::DraftRelease ? $this->payload($type, $entry, $variant, $row, 'released') : null;

            $heads[] = [$entry, $variant, $draft, $released];
            $entries[$entry->toString()] = true;
        }

        if ($entries !== []) {
            $db->table($type->name->table())->whereIn(TypeRows::ENTRY, array_keys($entries))->delete();
        }

        $rows = new TypeRows($db);

        foreach ($heads as [$entry, $variant, $draft, $released]) {
            if ($released instanceof FieldValues) {
                $rows->release($type, $entry, $variant, $released);
            }

            $rows->write($type, $entry, $variant, $draft);
        }

        return [count($entries), count($heads)];
    }

    /**
     * The fields of one payload of the row, $source being draft, released or snapshot; null when
     * the head has none.
     *
     * @throws RebuildRefused when the payload was written under another schema version than the type's
     */
    private function payload(TypeDefinition $type, EntryId $entry, VariantKey $variant, object $row, string $source): ?FieldValues
    {
        $content = property_exists($row, $source.'_content') ? $row->{$source.'_content'} : null;

        if ($content === null) {
            return null;
        }

        $schema = $this->integer($row, $source.'_schema');
        $format = $this->integer($row, $source.'_format');

        if ($schema !== $type->version) {
            throw RebuildRefused::schemaVersion($type->name, $entry, $variant, $schema, $type->version);
        }

        if ($format !== StoredContent::FORMAT_VERSION) {
            throw new LogicException(sprintf('The variant %s of the entry %s holds a payload of format %d, and the kernel writes format %d.', $variant->value, $entry->toString(), $format, StoredContent::FORMAT_VERSION));
        }

        return StoredContent::fields($type, is_string($content) ? $content : throw new UnexpectedValueException(sprintf('A payload is JSON text, got %s.', get_debug_type($content))));
    }

    /**
     * Runs the work in a transaction of its own under the context, and commits it; rolls it back
     * when the work throws.
     *
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $work
     * @return T
     *
     * @throws LogicException when the connection is already in a transaction
     */
    private function transaction(AccessContext $access, Closure $work): mixed
    {
        $name = $this->connection ?? $this->connections->getDefaultConnection();
        $db = $this->connections->connection($name);

        if ($db->transactionLevel() > 0) {
            throw new LogicException(sprintf('A rebuild runs each chunk in a transaction of its own, but the connection "%s" is already in a transaction.', $name));
        }

        $db->beginTransaction();

        try {
            $db->statement(self::READ_COMMITTED);
            $db->statement(self::TIMEOUT, [(string) self::TRANSACTION_MILLISECONDS]);
            new ActorContext($this->connections, $name)->set($access);
            $result = $work($db);
        } catch (Throwable $exception) {
            $this->rollBack($db, $exception);
        }

        try {
            $db->commit();
        } catch (Throwable $exception) {
            // A failed COMMIT ends the transaction on the server; this ends it on the connection.
            $this->rollBack($db, $exception);
        }

        return $result;
    }

    /**
     * Rolls the transaction back and throws what ended it. When Postgres ended the session, as it
     * does at the transaction's time limit, the rollback cannot reach the server, which has ended
     * the transaction already; the connection then holds no transaction, and the cause is thrown.
     */
    private function rollBack(ConnectionInterface $db, Throwable $cause): never
    {
        try {
            $db->rollBack();
        } catch (Throwable $failed) {
            throw $db->transactionLevel() === 0 ? $cause : $failed;
        }

        throw $cause;
    }

    private function text(mixed $row, string $column): string
    {
        $value = is_object($row) && property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a rebuild\'s read is text, got %s.', $column, get_debug_type($value)));
    }

    private function integer(object $row, string $column): int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a rebuild\'s read is an integer, got %s.', $column, get_debug_type($value)));
    }
}
