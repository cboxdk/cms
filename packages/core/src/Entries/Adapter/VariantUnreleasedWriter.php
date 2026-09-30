<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantUnreleased;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleased as VariantUnreleasedEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleasedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes VariantUnreleased in the commit (PRD 4.1, 5.6, 6.2 phase 7, 6.4), when an entry's content
 * is unpublished, for the entry's type as the TypeCatalog gives it:
 *
 * 1. The head in `variant_heads`: it points at no published revision any more, its release state
 *    is unreleased and it takes the context's version, in one statement that moves only a
 *    released head. The published revision stays, so a later release can release it again.
 * 2. The unrelease in `release_log`: the action `unreleased` without a revision, the time it took
 *    effect, which is the changeset's, and the changeset.
 * 3. The type table's released row is removed, and its values are kept as the draft row where the
 *    variant had none, through TypeRows.
 *
 * It returns variant.unreleased. Every statement is by key, so an unpublish costs the same however
 * many revisions the variant has (GUARDRAILS 4.1). It runs as the app role under the call's actor
 * context, inside the command transaction; the actor reaches the entry's home.
 */
#[Internal]
final readonly class VariantUnreleasedWriter implements MutationWriter
{
    /** The release state of a head that has no released revision, and the release log's action (PRD 6.4). */
    public const string UNRELEASED = 'unreleased';

    /** The release state a head is unreleased from. */
    public const string RELEASED = 'released';

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
        return VariantUnreleased::class;
    }

    /**
     * @throws LogicException when the entry, its type or a released head is not there, which the kernel's checks rule out
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof VariantUnreleased) {
            throw new InvalidArgumentException(sprintf('The unrelease writer writes VariantUnreleased, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $entry = $mutation->entry->toString();
        $variant = $mutation->variant->value;
        $typeId = $db->table('entries')->where('id', $entry)->useWritePdo()->value('type_id');
        $type = is_string($typeId) ? $this->types->find(TypeId::fromString($typeId)) : null;

        if (! $type instanceof TypeDefinition) {
            throw new LogicException(sprintf('The entry %s is not there, or its type is not a type of this installation.', $entry));
        }

        $moved = $db->table('variant_heads')
            ->where('entry_id', $entry)
            ->where('variant', $variant)
            ->where('release_state', self::RELEASED)
            ->update(['published_revision_id' => null, 'release_state' => self::UNRELEASED, 'version' => $context->version->value]);

        if ($moved !== 1) {
            throw new LogicException(sprintf('The head of the variant %s of the entry %s is not there or is not released.', $variant, $entry));
        }

        $db->table('release_log')->insert([
            'entry_id' => $entry,
            'variant' => $variant,
            'action' => self::UNRELEASED,
            'revision_id' => null,
            'effective_at' => Timestamps::of($context->at),
            'changeset_id' => $context->changesetId->toString(),
        ]);

        new TypeRows($db)->unrelease($type, $mutation->entry, $mutation->variant);

        return [new VariantUnreleasedEvent($context->version->value, new VariantUnreleasedV1(
            $mutation->entry,
            new VariantRef($mutation->entry, $mutation->variant),
            $mutation->revision->value,
        ))];
    }
}
