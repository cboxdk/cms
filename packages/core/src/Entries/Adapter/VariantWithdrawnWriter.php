<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantWithdrawn;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Events\VariantWithdrawn as VariantWithdrawnEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantWithdrawnV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;
use UnexpectedValueException;

/**
 * Writes VariantWithdrawn in the commit (PRD 4.1, 6.2 phase 7, 10.3), when a language's variant
 * of an entry is deleted, for the entry's type as the TypeCatalog gives it:
 *
 * 1. The head in `variant_heads`: it points at no published revision any more, its release state
 *    is withdrawn and it takes the context's version, in one statement (WITHDRAW) that moves only
 *    a head that is not withdrawn and returns the release state before and the number of the
 *    revision released until then. The revisions stay: they are history.
 * 2. The withdrawal in `release_log`: the action `withdrawn` without a revision, the time it took
 *    effect, which is the changeset's, and the changeset.
 * 3. Every row of the variant in the type's table, released and draft, is removed through
 *    TypeRows, so no read of the type finds the language any more; a rebuild leaves a withdrawn
 *    language without a row too.
 * 4. Every relation edge of the variant, released and draft, is removed (PRD 5.11), through
 *    RelationRows; a rebuild writes none for a withdrawn language.
 *
 * It returns variant.withdrawn. Every statement is by key, so a deletion costs the same however
 * many revisions the variant has (GUARDRAILS 4.1). It runs as the app role under the call's actor
 * context, inside the command transaction; the actor reaches the entry's home in the locale.
 */
#[Internal]
final readonly class VariantWithdrawnWriter implements MutationWriter
{
    /** The release state of a withdrawn head, and the release log's action (PRD 6.4). */
    public const string WITHDRAWN = 'withdrawn';

    /** Withdraws the head unless it is withdrawn already; the state before and the released revision's number. */
    public const string WITHDRAW = <<<'SQL'
        with old as (
            select h.entry_id, h.variant, h.release_state, h.published_revision_id
            from variant_heads h
            where h.entry_id = ?::uuid and h.variant = ? and h.release_state <> 'withdrawn'
            for no key update
        )
        update variant_heads h
           set published_revision_id = null, release_state = 'withdrawn', version = ?
          from old
         where h.entry_id = old.entry_id and h.variant = old.variant
        returning old.release_state as previous, (select r.rev_no from revisions r where r.revision_id = old.published_revision_id) as revision
        SQL;

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
        return VariantWithdrawn::class;
    }

    /**
     * @throws LogicException when the entry, its type or a head that is not withdrawn is not there, which the kernel's checks rule out
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof VariantWithdrawn) {
            throw new InvalidArgumentException(sprintf('The withdrawal writer writes VariantWithdrawn, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $entry = $mutation->entry->toString();
        $variant = $mutation->variant->value;
        $typeId = $db->table('entries')->where('id', $entry)->useWritePdo()->value('type_id');
        $type = is_string($typeId) ? $this->types->find(TypeId::fromString($typeId)) : null;

        if (! $type instanceof TypeDefinition) {
            throw new LogicException(sprintf('The entry %s is not there, or its type is not a type of this installation.', $entry));
        }

        $rows = $db->select(self::WITHDRAW, [$entry, $variant, $context->version->value], false);

        if (count($rows) !== 1 || ! is_object($rows[0])) {
            throw new LogicException(sprintf('The head of the variant %s of the entry %s is not there or is withdrawn already.', $variant, $entry));
        }

        $previous = ReleaseState::tryFrom(property_exists($rows[0], 'previous') && is_string($rows[0]->previous) ? $rows[0]->previous : '')
            ?? throw new UnexpectedValueException('The release state before is not a release state of PRD 6.4.');
        $revision = property_exists($rows[0], 'revision') ? $rows[0]->revision : null;

        if ($revision !== null && ! is_int($revision)) {
            throw new UnexpectedValueException(sprintf('The number of the released revision is an integer, got %s.', get_debug_type($revision)));
        }

        $db->table('release_log')->insert([
            'entry_id' => $entry,
            'variant' => $variant,
            'action' => self::WITHDRAWN,
            'revision_id' => null,
            'effective_at' => Timestamps::of($context->at),
            'changeset_id' => $context->changesetId->toString(),
        ]);

        new TypeRows($db)->remove($type, $mutation->entry, $mutation->variant);
        new RelationRows($db)->remove($mutation->entry, $mutation->variant);

        return [new VariantWithdrawnEvent($context->version->value, new VariantWithdrawnV1(
            $mutation->entry,
            new VariantRef($mutation->entry, $mutation->variant),
            $previous,
            $revision,
        ))];
    }
}
