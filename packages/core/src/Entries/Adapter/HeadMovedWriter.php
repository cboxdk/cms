<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevisedV1;
use Cbox\Cms\Core\Pipeline\Domain\BatchMutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes HeadMoved in the commit (PRD 5.4, 6.2 phase 7): the variant's head in `variant_heads`
 * points at the revision it moved to and takes that revision's schema version and the context's
 * version, in one statement. The revision is the row of `revisions` with the number, for a type
 * with full history, or the variant's head snapshot at that number, for one that keeps no
 * revisions, whose head has no draft revision to point at. It returns variant.revised.
 *
 * The RevisionCreated of the same plan writes the revision first, so a head that finds neither has
 * a plan the kernel should not have committed, and the writer throws.
 */
#[Internal]
final readonly class HeadMovedWriter implements BatchMutationWriter
{
    /** The moves of a run of heads, each with the revision or snapshot it moves to. */
    public const string MOVE = <<<'SQL'
        update variant_heads as h
        set draft_revision_id = c.revision_id, schema_version = c.schema_version, version = m.version
        from unnest(?::uuid[], ?::text[], ?::bigint[], ?::bigint[]) as m(entry_id, variant, rev_no, version)
        cross join lateral (
            select r.revision_id, r.schema_version from revisions as r where r.entry_id = m.entry_id and r.variant = m.variant and r.rev_no = m.rev_no
            union all
            select null, s.schema_version from head_snapshots as s where s.entry_id = m.entry_id and s.variant = m.variant and s.rev_no = m.rev_no
            limit 1
        ) as c
        where h.entry_id = m.entry_id and h.variant = m.variant
        SQL;

    /** The most heads one update moves. */
    public const int ROWS_PER_STATEMENT = 1000;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return HeadMoved::class;
    }

    /**
     * @throws LogicException when the variant has no head, or no revision or snapshot with the number it moves to
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        return $this->writeAll([new PendingMutation($mutation, $context)]);
    }

    /**
     * A run of moves in one statement per ROWS_PER_STATEMENT.
     *
     * @throws LogicException when a variant has no head, or no revision or snapshot with the number it moves to
     */
    #[Override]
    public function writeAll(array $mutations): array
    {
        $events = [];
        $moves = [];

        foreach ($mutations as $pending) {
            $mutation = $pending->mutation;

            if (! $mutation instanceof HeadMoved) {
                throw new InvalidArgumentException(sprintf('The head writer writes HeadMoved, not %s.', $mutation::class));
            }

            $moves[] = [$mutation->entry->toString(), $mutation->variant->value, $mutation->to->value, $pending->context->version->value];
            $events[] = new VariantRevised($pending->context->version->value, new VariantRevisedV1(
                $mutation->entry,
                new VariantRef($mutation->entry, $mutation->variant),
                $mutation->to->value,
                $mutation->from?->value,
            ));
        }

        $db = $this->connections->connection($this->connection);

        foreach (array_chunk($moves, self::ROWS_PER_STATEMENT) as $chunk) {
            $moved = $db->update(self::MOVE, [
                '{'.implode(',', array_column($chunk, 0)).'}',
                '{'.implode(',', array_map(static fn (string $variant): string => '"'.$variant.'"', array_column($chunk, 1))).'}',
                '{'.implode(',', array_column($chunk, 2)).'}',
                '{'.implode(',', array_column($chunk, 3)).'}',
            ]);

            if ($moved !== count($chunk)) {
                throw new LogicException(sprintf(
                    'The head of the variant %s of the entry %s cannot move to revision %d: the head or the revision is not there%s.',
                    $chunk[0][1],
                    $chunk[0][0],
                    $chunk[0][2],
                    count($chunk) === 1 ? '' : sprintf(', or the head or revision of another of the %d heads, of which %d moved', count($chunk), $moved),
                ));
            }
        }

        return $events;
    }
}
