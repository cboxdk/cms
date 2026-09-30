<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantFields;
use Cbox\Cms\Core\Pipeline\Domain\BatchMutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;
use UnexpectedValueException;

/**
 * Writes RevisionCreated in the commit (PRD 4.1, 5.4, 6.2 phase 7, 11.6), for the type the
 * mutation names, as the TypeCatalog gives it, at the type's schema version:
 *
 * 1. The variant's head, when this is the variant's first revision: its row in `variant_heads`,
 *    unreleased, with no draft revision yet, at the context's version. HeadMoved points it at the
 *    revision.
 * 2. The content. A type with full history gets the revision's row in `revisions`, a draft, with
 *    its number, the schema version and the changeset, and its payload in `revision_payloads`. A
 *    type whose history is audit-only or none keeps no revisions (PRD 4.1): the variant's head
 *    snapshot in `head_snapshots` takes the content, the number and the schema version in place.
 * 3. The type table's row, through TypeRows: the draft row for stages draft-release, the released
 *    row for stages none.
 *
 * A run of revisions is written in one statement per table and type (writeAll()). It returns no
 * event; the head's move tells about the revision. It runs as the app role under the
 * call's actor context, inside the command transaction.
 */
#[Internal]
final readonly class RevisionCreatedWriter implements BatchMutationWriter
{
    /** The kind of a revision a save writes (PRD 4.1); a release writes the published kind. */
    public const string KIND = 'draft';

    /** The release state of a new head (PRD 6.4). */
    public const string UNRELEASED = 'unreleased';

    /** The most rows one insert carries, well below Postgres' 65535 bindings. */
    public const int ROWS_PER_STATEMENT = 1000;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private TypeCatalog $types,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return RevisionCreated::class;
    }

    /**
     * @throws LogicException when the installation has no type with the mutation's id, which the pipeline rules out
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        return $this->writeAll([new PendingMutation($mutation, $context)]);
    }

    /**
     * A run of revisions in one statement per table and type: the new heads, the revisions and
     * their payloads, the head snapshots, and the type table's rows of each type.
     *
     * @throws LogicException when the installation has no type with a mutation's id, which the pipeline rules out
     */
    #[Override]
    public function writeAll(array $mutations): array
    {
        $db = $this->connections->connection($this->connection);
        $heads = [];
        $revisions = [];
        $payloads = [];
        $snapshots = [];
        $rows = [];
        $types = [];

        foreach ($mutations as $pending) {
            $mutation = $pending->mutation;

            if (! $mutation instanceof RevisionCreated) {
                throw new InvalidArgumentException(sprintf('The revision writer writes RevisionCreated, not %s.', $mutation::class));
            }

            $type = $this->types->find($mutation->type)
                ?? throw new LogicException(sprintf('No type of this installation has the id %s; the pipeline refuses such a plan before the commit.', $mutation->type->toString()));
            $at = Timestamps::of($pending->context->at);
            $content = StoredContent::payload($mutation->fields);
            $key = $this->key($mutation->entry->toString(), $mutation->variant->value, $mutation->revision->value);

            if ($mutation->revision->value === 1) {
                $heads[] = [
                    'entry_id' => $mutation->entry->toString(),
                    'variant' => $mutation->variant->value,
                    'draft_revision_id' => null,
                    'published_revision_id' => null,
                    'schema_version' => $type->version,
                    'release_state' => self::UNRELEASED,
                    'workflow_state' => null,
                    'next_transition_at' => null,
                    'version' => $pending->context->version->value,
                    'created_at' => $at,
                ];
            }

            if ($type->capabilities->history === History::Full) {
                $revisions[$key] = [
                    $mutation->entry->toString(),
                    $mutation->variant->value,
                    $mutation->revision->value,
                    self::KIND,
                    $type->version,
                    $pending->context->changesetId->toString(),
                    $at,
                ];
                $payloads[$key] = $content;
            } else {
                $snapshots[] = [
                    'entry_id' => $mutation->entry->toString(),
                    'variant' => $mutation->variant->value,
                    'rev_no' => $mutation->revision->value,
                    'schema_version' => $type->version,
                    'format_version' => StoredContent::FORMAT_VERSION,
                    'content' => $content,
                    'updated_at' => $at,
                ];
            }

            $types[$type->id->toString()] = $type;
            $rows[$type->id->toString()][] = new VariantFields($mutation->entry, $mutation->variant, $mutation->fields);
        }

        foreach (array_chunk($heads, self::ROWS_PER_STATEMENT) as $chunk) {
            $db->table('variant_heads')->insert($chunk);
        }

        foreach (array_chunk($revisions, self::ROWS_PER_STATEMENT, true) as $chunk) {
            $this->revisions($db, $chunk, $payloads);
        }

        foreach (array_chunk($snapshots, self::ROWS_PER_STATEMENT) as $chunk) {
            $db->table('head_snapshots')->upsert(
                $chunk,
                ['entry_id', 'variant'],
                ['rev_no', 'schema_version', 'format_version', 'content', 'updated_at'],
            );
        }

        $typeRows = new TypeRows($db);

        foreach ($rows as $type => $variants) {
            $typeRows->writeAll($types[$type], $variants);
        }

        return [];
    }

    /**
     * The revisions' rows in one statement, and their payloads in another, matched by the key
     * each revision's id comes back with.
     *
     * @param  array<string, array{string, string, int, string, int, string, string}>  $revisions  by key()
     * @param  array<string, string>  $payloads  the content by key()
     */
    private function revisions(ConnectionInterface $db, array $revisions, array $payloads): void
    {
        $values = implode(', ', array_fill(0, count($revisions), '(?::uuid, ?, ?, ?, ?, ?::uuid, ?::timestamptz)'));
        $inserted = $db->select(
            'insert into revisions (entry_id, variant, rev_no, kind, schema_version, changeset_id, created_at) values '.$values.' returning entry_id::text as entry_id, variant, rev_no, revision_id',
            array_merge(...array_values($revisions)),
            false,
        );
        $rows = [];

        foreach ($inserted as $row) {
            if (! is_object($row) || ! is_string($row->entry_id ?? null) || ! is_string($row->variant ?? null) || ! is_int($row->rev_no ?? null) || ! is_int($row->revision_id ?? null)) {
                throw new UnexpectedValueException('An inserted revision comes back with its entry, variant, number and id.');
            }

            $key = $this->key($row->entry_id, $row->variant, $row->rev_no);
            $rows[] = [
                'revision_id' => $row->revision_id,
                'kind' => self::KIND,
                'format_version' => StoredContent::FORMAT_VERSION,
                'content' => $payloads[$key] ?? throw new LogicException(sprintf('The inserted revision %s has no payload in the run.', $key)),
            ];
        }

        $db->table('revision_payloads')->insert($rows);
    }

    /**
     * A revision's key within a run: its entry, variant and number.
     */
    private function key(string $entry, string $variant, int $number): string
    {
        return $entry.' '.$variant.' '.$number;
    }
}
