<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWithdrawn;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChangedV1;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;
use UnexpectedValueException;

/**
 * Writes PlacementWithdrawn in the commit (PRD 5.7, 8.10, 10.3, 6.2 phase 7): the released
 * PlacementLocale of the placement in the locale is withdrawn, loses its window, its next
 * transition and its canonical flag, and the placement takes the context's version, so its URL
 * answers 410. Deleting a translation is decided on the entry's home in the locale (PRD 5.10), so
 * the placement can be below a node the actor's regions do not reach; the owner function
 * `cms_placement_withdraw` writes it, only in the transaction of a variant.delete changeset by the
 * context's actor, and only a placement that is not withdrawn already. It returns
 * placement.visibility_changed from the state before to withdrawn.
 */
#[Internal]
final readonly class PlacementWithdrawnWriter implements MutationWriter
{
    public const string WITHDRAW = 'select entry_id, node_id, previous from cms_placement_withdraw(?::uuid, ?, ?::bigint, ?::uuid)';

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
        return PlacementWithdrawn::class;
    }

    /**
     * @throws LogicException when the function withdrew no row, which it raises for itself
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementWithdrawn) {
            throw new InvalidArgumentException(sprintf('The placement withdrawal writer writes PlacementWithdrawn, not %s.', $mutation::class));
        }

        $rows = $this->connections->connection($this->connection)->select(self::WITHDRAW, [
            $mutation->placement->toString(),
            $mutation->locale->value,
            $context->version->value,
            $context->changesetId->toString(),
        ], false);

        if (count($rows) !== 1) {
            throw new LogicException(sprintf('The placement %s was not withdrawn in %s.', $mutation->placement->toString(), $mutation->locale->value));
        }

        $row = PlacementRows::object($rows[0]);
        $previous = Visibility::tryFrom(PlacementRows::text($row, 'previous'))
            ?? throw new UnexpectedValueException('The visibility before is not a state of PRD 6.4.');

        return [new PlacementVisibilityChanged($context->version->value, new PlacementVisibilityChangedV1(
            $mutation->placement,
            EntryId::fromString(PlacementRows::text($row, 'entry_id')),
            NodeId::fromString(PlacementRows::text($row, 'node_id')),
            $mutation->locale,
            $previous,
            Visibility::Withdrawn,
            null,
            null,
            null,
        ))];
    }
}
