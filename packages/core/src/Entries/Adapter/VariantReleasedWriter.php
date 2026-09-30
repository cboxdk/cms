<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased as VariantReleasedEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleasedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
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
 * revisions the variant has (GUARDRAILS 4.1). It runs as the app role under the call's actor
 * context, inside the command transaction.
 */
#[Internal]
final readonly class VariantReleasedWriter implements MutationWriter
{
    /** The revision to release, with its payload, the variant's highest number and the number released before. */
    public const string SOURCE = <<<'SQL'
        select r.revision_id, r.kind, r.schema_version, p.format_version, p.content::text as content,
            (select max(l.rev_no) from revisions as l where l.entry_id = r.entry_id and l.variant = r.variant) as latest,
            (select b.rev_no from variant_heads as h join revisions as b on b.revision_id = h.published_revision_id where h.entry_id = r.entry_id and h.variant = r.variant) as previous
        from revisions as r
        join revision_payloads as p on p.revision_id = r.revision_id and p.kind = r.kind
        where r.entry_id = ?::uuid and r.variant = ? and r.rev_no = ?
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
        if (! $mutation instanceof VariantReleased) {
            throw new InvalidArgumentException(sprintf('The release writer writes VariantReleased, not %s.', $mutation::class));
        }

        $type = $this->types->find($mutation->type)
            ?? throw new LogicException(sprintf('No type of this installation has the id %s; the pipeline refuses such a plan before the commit.', $mutation->type->toString()));
        $db = $this->connections->connection($this->connection);
        $entry = $mutation->entry->toString();
        $variant = $mutation->variant->value;

        $source = $db->selectOne(self::SOURCE, [$entry, $variant, $mutation->revision->value], false);

        if (! is_object($source)) {
            throw new LogicException(sprintf('The variant %s of the entry %s has no revision %d to release; the pipeline refuses such a plan before the commit.', $variant, $entry, $mutation->revision->value));
        }

        $schemaVersion = $this->integer($source, 'schema_version');

        if ($schemaVersion !== $type->version) {
            throw new LogicException(sprintf('Revision %d of the entry %s was written under schema version %d, not the version %d of its type; the pipeline refuses such a release before the commit.', $mutation->revision->value, $entry, $schemaVersion, $type->version));
        }

        $content = $this->text($source, 'content');
        [$published, $number] = $this->text($source, 'kind') === self::PUBLISHED
            ? [$this->integer($source, 'revision_id'), $mutation->revision->value]
            : $this->publish($db, $mutation, $context, $source, $content);

        $moved = $db->table('variant_heads')
            ->where('entry_id', $entry)
            ->where('variant', $variant)
            ->whereIn('release_state', self::RELEASABLE)
            ->update(['published_revision_id' => $published, 'release_state' => self::RELEASED, 'version' => $context->version->value]);

        if ($moved !== 1) {
            throw new LogicException(sprintf('The head of the variant %s of the entry %s is not there or is withdrawn, which only reinstate releases again.', $variant, $entry));
        }

        $db->table('release_log')->insert([
            'entry_id' => $entry,
            'variant' => $variant,
            'action' => self::RELEASED,
            'revision_id' => $published,
            'effective_at' => Timestamps::of($context->at),
            'changeset_id' => $context->changesetId->toString(),
        ]);

        new TypeRows($db)->release($type, $mutation->entry, $mutation->variant, StoredContent::fields($type, $content));

        return [new VariantReleasedEvent($context->version->value, new VariantReleasedV1(
            $mutation->entry,
            new VariantRef($mutation->entry, $mutation->variant),
            $mutation->revision->value,
            $number,
            $this->integerOrNull($source, 'previous'),
        ))];
    }

    /**
     * Writes the published revision of a draft: the next number, the draft's schema version and a
     * copy of its payload. Returns its id and its number.
     *
     * @return array{int, int}
     */
    private function publish(ConnectionInterface $db, VariantReleased $mutation, MutationContext $context, object $source, string $content): array
    {
        $number = $this->integer($source, 'latest') + 1;
        $id = $db->table('revisions')->insertGetId([
            'entry_id' => $mutation->entry->toString(),
            'variant' => $mutation->variant->value,
            'rev_no' => $number,
            'kind' => self::PUBLISHED,
            'schema_version' => $this->integer($source, 'schema_version'),
            'changeset_id' => $context->changesetId->toString(),
            'created_at' => Timestamps::of($context->at),
        ], 'revision_id');

        $db->table('revision_payloads')->insert([
            'revision_id' => $id,
            'kind' => self::PUBLISHED,
            'format_version' => $this->integer($source, 'format_version'),
            'content' => $content,
        ]);

        return [$id, $number];
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
