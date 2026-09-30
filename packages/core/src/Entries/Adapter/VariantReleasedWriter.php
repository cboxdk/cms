<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantFields;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased as VariantReleasedEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleasedV1;
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
 * Writes VariantReleased in the commit (PRD 4.1, 5.6, 6.2 phase 7, 6.4), for the type the mutation
 * names, as the TypeCatalog gives it:
 *
 * 1. The published revision. A released revision is kept as a revision of the kind `published`,
 *    whose payload lives in the append-only partition of published payloads and which the public
 *    reads (PRD 5.10): a draft revision is released as a new published revision after the
 *    variant's highest number, with the draft's schema version and a copy of its payload, written
 *    in the changeset; a revision that is published already is released as it is.
 * 2. The head in `variant_heads`: it points at the published revision, its release state is
 *    released and it takes the context's version, in one statement. A withdrawn variant is
 *    released only by reinstate (PRD 6.4, invariant 7), so its head is not moved and the writer
 *    throws.
 * 3. The release in `release_log`: the published revision, the time it took effect, which is the
 *    changeset's, and the changeset.
 * 4. The type table's released row from the revision's fields, through TypeRows, with the draft row
 *    kept only where the pending draft differs.
 *
 * It returns variant.released. Every statement is by key, so a release costs the same however many
 * revisions the variant has, and a run of releases costs one statement per step (GUARDRAILS 4.1). It runs as the app role under the call's actor
 * context, inside the command transaction.
 */
#[Internal]
final readonly class VariantReleasedWriter implements BatchMutationWriter
{
    /**
     * The revisions to release, in the order given, with their payloads, each variant's highest
     * number and the number it released before.
     */
    public const string SOURCES = <<<'SQL'
        select k.n, r.revision_id, r.kind, r.schema_version, p.format_version, p.content::text as content,
            (select max(l.rev_no) from revisions as l where l.entry_id = r.entry_id and l.variant = r.variant) as latest,
            (select b.rev_no from variant_heads as h join revisions as b on b.revision_id = h.published_revision_id where h.entry_id = r.entry_id and h.variant = r.variant) as previous
        from unnest(?::uuid[], ?::text[], ?::bigint[]) with ordinality as k(entry_id, variant, rev_no, n)
        join revisions as r on r.entry_id = k.entry_id and r.variant = k.variant and r.rev_no = k.rev_no
        join revision_payloads as p on p.revision_id = r.revision_id and p.kind = r.kind
        order by k.n
        SQL;

    /** The heads of a run, each moved to its published revision. */
    public const string MOVE_HEADS = <<<'SQL'
        update variant_heads as h
        set published_revision_id = m.published, release_state = 'released', version = m.version
        from unnest(?::uuid[], ?::text[], ?::bigint[], ?::bigint[]) as m(entry_id, variant, published, version)
        where h.entry_id = m.entry_id and h.variant = m.variant and h.release_state in ('unreleased', 'released')
        SQL;

    /** The kind of a revision a release keeps (PRD 4.1). */
    public const string PUBLISHED = 'published';

    /** The release state and the release log's action of a release (PRD 5.6, 6.4). */
    public const string RELEASED = 'released';

    /** @var list<string> the release states a release moves a head from; withdrawn is left only by reinstate */
    public const array RELEASABLE = ['unreleased', 'released'];

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
        return VariantReleased::class;
    }

    /**
     * @throws LogicException when the type, the revision or a releasable head is not there, which the kernel's checks rule out
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        return $this->writeAll([new PendingMutation($mutation, $context)]);
    }

    /**
     * A run of releases in one statement per step. A run that releases one variant twice is
     * written one release after the other, because the second numbers its revision after the
     * first's.
     *
     * @throws LogicException when a type, a revision or a releasable head is not there, which the kernel's checks rule out
     */
    #[Override]
    public function writeAll(array $mutations): array
    {
        $releases = [];
        $variants = [];

        foreach ($mutations as $pending) {
            $mutation = $pending->mutation;

            if (! $mutation instanceof VariantReleased) {
                throw new InvalidArgumentException(sprintf('The release writer writes VariantReleased, not %s.', $mutation::class));
            }

            $releases[] = [$mutation, $pending->context];
            $variants[$mutation->entry->toString().' '.$mutation->variant->value] = true;
        }

        if (count($variants) < count($releases)) {
            $events = [];

            foreach ($mutations as $pending) {
                array_push($events, ...$this->writeAll([$pending]));
            }

            return $events;
        }

        return $this->release($this->connections->connection($this->connection), $releases);
    }

    /**
     * @param  non-empty-list<array{VariantReleased, MutationContext}>  $releases  of distinct variants
     * @return list<VariantReleasedEvent>
     */
    private function release(ConnectionInterface $db, array $releases): array
    {
        $sources = $this->sources($db, $releases);
        $drafts = [];

        foreach ($releases as $index => [$mutation, $context]) {
            $source = $sources[$index];
            $type = $this->types->find($mutation->type)
                ?? throw new LogicException(sprintf('No type of this installation has the id %s; the pipeline refuses such a plan before the commit.', $mutation->type->toString()));
            $schemaVersion = $this->integer($source, 'schema_version');

            if ($schemaVersion !== $type->version) {
                throw new LogicException(sprintf('Revision %d of the entry %s was written under schema version %d, not the version %d of its type; the pipeline refuses such a release before the commit.', $mutation->revision->value, $mutation->entry->toString(), $schemaVersion, $type->version));
            }

            if ($this->text($source, 'kind') !== self::PUBLISHED) {
                $drafts[$index] = [
                    $mutation->entry->toString(),
                    $mutation->variant->value,
                    $this->integer($source, 'latest') + 1,
                    self::PUBLISHED,
                    $schemaVersion,
                    $context->changesetId->toString(),
                    Timestamps::of($context->at),
                ];
            }
        }

        $published = $this->publish($db, $drafts, $sources);
        $heads = [[], [], [], []];
        $log = [];
        $rows = [];
        $types = [];
        $events = [];

        foreach ($releases as $index => [$mutation, $context]) {
            $source = $sources[$index];
            [$id, $number] = $published[$index] ?? [$this->integer($source, 'revision_id'), $mutation->revision->value];
            $type = $this->types->find($mutation->type) ?? throw new LogicException('The type was found above.');

            $heads[0][] = $mutation->entry->toString();
            $heads[1][] = '"'.$mutation->variant->value.'"';
            $heads[2][] = $id;
            $heads[3][] = $context->version->value;
            $log[] = [
                'entry_id' => $mutation->entry->toString(),
                'variant' => $mutation->variant->value,
                'action' => self::RELEASED,
                'revision_id' => $id,
                'effective_at' => Timestamps::of($context->at),
                'changeset_id' => $context->changesetId->toString(),
            ];
            $types[$type->id->toString()] = $type;
            $rows[$type->id->toString()][] = new VariantFields($mutation->entry, $mutation->variant, StoredContent::fields($type, $this->text($source, 'content')));
            $events[] = new VariantReleasedEvent($context->version->value, new VariantReleasedV1(
                $mutation->entry,
                new VariantRef($mutation->entry, $mutation->variant),
                $mutation->revision->value,
                $number,
                $this->integerOrNull($source, 'previous'),
            ));
        }

        $moved = $db->update(self::MOVE_HEADS, array_map(static fn (array $column): string => '{'.implode(',', $column).'}', $heads));

        if ($moved !== count($releases)) {
            throw new LogicException(sprintf('Of %d heads to release, %d moved: a head is not there or is withdrawn, which only reinstate releases again.', count($releases), $moved));
        }

        $db->table('release_log')->insert($log);
        $typeRows = new TypeRows($db);

        foreach ($rows as $type => $variants) {
            $typeRows->releaseAll($types[$type], $variants);
        }

        return $events;
    }

    /**
     * The source of each release, by its index in the run.
     *
     * @param  non-empty-list<array{VariantReleased, MutationContext}>  $releases
     * @return array<int, object>
     */
    private function sources(ConnectionInterface $db, array $releases): array
    {
        $rows = $db->select(self::SOURCES, [
            '{'.implode(',', array_map(static fn (array $release): string => $release[0]->entry->toString(), $releases)).'}',
            '{'.implode(',', array_map(static fn (array $release): string => '"'.$release[0]->variant->value.'"', $releases)).'}',
            '{'.implode(',', array_map(static fn (array $release): int => $release[0]->revision->value, $releases)).'}',
        ], false);
        $sources = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new UnexpectedValueException(sprintf('A revision to release is a row, got %s.', get_debug_type($row)));
            }

            $sources[$this->integer($row, 'n') - 1] = $row;
        }

        foreach ($releases as $index => [$mutation]) {
            if (! isset($sources[$index])) {
                throw new LogicException(sprintf('The variant %s of the entry %s has no revision %d to release; the pipeline refuses such a plan before the commit.', $mutation->variant->value, $mutation->entry->toString(), $mutation->revision->value));
            }
        }

        return $sources;
    }

    /**
     * Writes the published revisions of the drafts, the next number and the draft's schema version
     * each, and a copy of each draft's payload. Returns each one's id and number by its index in
     * the run.
     *
     * @param  array<int, array{string, string, int, string, int, string, string}>  $drafts
     * @param  array<int, object>  $sources
     * @return array<int, array{int, int}>
     */
    private function publish(ConnectionInterface $db, array $drafts, array $sources): array
    {
        if ($drafts === []) {
            return [];
        }

        $values = implode(', ', array_fill(0, count($drafts), '(?::uuid, ?, ?, ?, ?, ?::uuid, ?::timestamptz)'));
        $inserted = $db->select(
            'insert into revisions (entry_id, variant, rev_no, kind, schema_version, changeset_id, created_at) values '.$values.' returning entry_id::text as entry_id, variant, rev_no, revision_id',
            array_merge(...array_values($drafts)),
            false,
        );
        $ids = [];

        foreach ($inserted as $row) {
            if (! is_object($row)) {
                throw new UnexpectedValueException(sprintf('A published revision comes back as a row, got %s.', get_debug_type($row)));
            }

            $ids[$this->text($row, 'entry_id').' '.$this->text($row, 'variant')] = $this->integer($row, 'revision_id');
        }

        $published = [];
        $payloads = [];

        foreach ($drafts as $index => $draft) {
            $id = $ids[$draft[0].' '.$draft[1]] ?? throw new LogicException(sprintf('The published revision of the variant %s of the entry %s did not come back.', $draft[1], $draft[0]));
            $published[$index] = [$id, $draft[2]];
            $payloads[] = [
                'revision_id' => $id,
                'kind' => self::PUBLISHED,
                'format_version' => $this->integer($sources[$index], 'format_version'),
                'content' => $this->text($sources[$index], 'content'),
            ];
        }

        $db->table('revision_payloads')->insert($payloads);

        return $published;
    }

    private function text(object $row, string $column): string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a revision is text, got %s.', $column, get_debug_type($value)));
    }

    private function integer(object $row, string $column): int
    {
        return $this->integerOrNull($row, $column) ?? throw new UnexpectedValueException(sprintf('The column %s of a revision is not null.', $column));
    }

    private function integerOrNull(object $row, string $column): ?int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value !== null && ! is_int($value)) {
            throw new UnexpectedValueException(sprintf('The column %s of a revision is an integer, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }
}
