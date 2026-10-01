<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
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
 * Writes PlacementClosed in the commit (PRD 5.7, 6.4, 6.2 phase 7): the released PlacementLocale of
 * the placement in the locale is hidden, loses its window and its next transition, and the
 * placement takes the context's version. Unpublishing is decided on the entry's home (PRD 5.10), so
 * the placement can be below a node the actor's regions do not reach; the owner function
 * `cms_placement_close` writes it, only in the transaction of an entry.unpublish changeset by the
 * context's actor, and only a placement that is scheduled or live. It returns
 * placement.visibility_changed from the state before to hidden.
 */
#[Internal]
final readonly class PlacementClosedWriter implements MutationWriter
{
    public const string CLOSE = 'select entry_id, node_id, previous from cms_placement_close(?::uuid, ?, ?::bigint, ?::uuid)';

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
        return PlacementClosed::class;
    }

    /**
     * @throws LogicException when the function closed no row, which it raises for itself
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementClosed) {
            throw new InvalidArgumentException(sprintf('The placement close writer writes PlacementClosed, not %s.', $mutation::class));
        }

        $rows = $this->connections->connection($this->connection)->select(self::CLOSE, [
            $mutation->placement->toString(),
            $mutation->locale->value,
            $context->version->value,
            $context->changesetId->toString(),
        ], false);

        if (count($rows) !== 1) {
            throw new LogicException(sprintf('The placement %s was not closed in %s.', $mutation->placement->toString(), $mutation->locale->value));
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
            Visibility::Hidden,
            null,
            null,
            null,
        ))];
    }
}
