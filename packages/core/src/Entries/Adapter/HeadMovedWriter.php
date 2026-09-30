<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevisedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
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
final readonly class HeadMovedWriter implements MutationWriter
{
    /** The move of one head, with the revision or snapshot it moves to. */
    public const string MOVE = <<<'SQL'
        update variant_heads as h
        set draft_revision_id = c.revision_id, schema_version = c.schema_version, version = ?
        from (
            select r.revision_id, r.schema_version from revisions as r where r.entry_id = ?::uuid and r.variant = ? and r.rev_no = ?
            union all
            select null, s.schema_version from head_snapshots as s where s.entry_id = ?::uuid and s.variant = ? and s.rev_no = ?
            limit 1
        ) as c
        where h.entry_id = ?::uuid and h.variant = ?
        SQL;

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
        if (! $mutation instanceof HeadMoved) {
            throw new InvalidArgumentException(sprintf('The head writer writes HeadMoved, not %s.', $mutation::class));
        }

        $entry = $mutation->entry->toString();
        $variant = $mutation->variant->value;
        $to = $mutation->to->value;

        $moved = $this->connections->connection($this->connection)->update(self::MOVE, [
            $context->version->value,
            $entry, $variant, $to,
            $entry, $variant, $to,
            $entry, $variant,
        ]);

        if ($moved !== 1) {
            throw new LogicException(sprintf(
                'The head of the variant %s of the entry %s cannot move to revision %d: the head or the revision is not there.',
                $variant,
                $entry,
                $to,
            ));
        }

        return [new VariantRevised($context->version->value, new VariantRevisedV1(
            $mutation->entry,
            new VariantRef($mutation->entry, $mutation->variant),
            $to,
            $mutation->from?->value,
        ))];
    }
}
