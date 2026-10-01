<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
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
 * Writes PlacementWindowSet in the commit (PRD 5.7, 6.4, 6.7, 6.2 phase 7): the window of the
 * placement's released PlacementLocale in the locale, the visibility state the window gives at the
 * changeset's time and next_transition_at, the time that state goes stale, in one statement that
 * also reads the state before; then the placement at the context's version. A null window hides the
 * placement and clears the window. It returns placement.visibility_changed.
 *
 * M1 has no scheduler (block B3), so the stored state is the one at the commit, and a read decides
 * visibility from the window at its own time. A withdrawn placement never gets here: the command
 * refuses it, and the statement leaves a withdrawn row as it is.
 */
#[Internal]
final readonly class PlacementWindowSetWriter implements MutationWriter
{
    /** The window, the state and the next transition, with the state before and the entry. */
    public const string UPDATE = <<<'SQL'
        with old as (
            select placement_id, stage, locale, visibility
            from placement_locales
            where placement_id = ?::uuid and stage = 'released' and locale = ? and visibility <> 'withdrawn'
            for no key update
        )
        update placement_locales as pl
        set visibility = ?, live_from = ?::timestamptz, live_until = ?::timestamptz, next_transition_at = ?::timestamptz
        from old
        where pl.placement_id = old.placement_id and pl.stage = old.stage and pl.locale = old.locale
        returning pl.entry_id, pl.node_id, old.visibility as previous
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
        return PlacementWindowSet::class;
    }

    /**
     * @throws LogicException when the placement has no locale to set, or it is withdrawn
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementWindowSet) {
            throw new InvalidArgumentException(sprintf('The placement window writer writes PlacementWindowSet, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $placement = $mutation->placement->toString();
        $window = $mutation->window;
        $visibility = Visibility::of($window, $context->at);
        $next = Visibility::nextTransition($window, $context->at);

        $rows = $db->select(self::UPDATE, [
            $placement,
            $mutation->locale->value,
            $visibility->value,
            PlacementRows::timestamp($window?->from),
            PlacementRows::timestamp($window?->until),
            PlacementRows::timestamp($next),
        ], false);

        if (count($rows) !== 1) {
            throw new LogicException(sprintf('The placement %s has no locale %s whose window can be set.', $placement, $mutation->locale->value));
        }

        $row = PlacementRows::object($rows[0]);
        $previous = Visibility::tryFrom(PlacementRows::text($row, 'previous'))
            ?? throw new UnexpectedValueException('The visibility before is not a state of PRD 6.4.');

        $db->table('placements')->where('id', $placement)->update(['version' => $context->version->value]);

        return [new PlacementVisibilityChanged($context->version->value, new PlacementVisibilityChangedV1(
            $mutation->placement,
            EntryId::fromString(PlacementRows::text($row, 'entry_id')),
            NodeId::fromString(PlacementRows::text($row, 'node_id')),
            $mutation->locale,
            $previous,
            $visibility,
            $window?->from,
            $window?->until,
            $next,
        ))];
    }
}
