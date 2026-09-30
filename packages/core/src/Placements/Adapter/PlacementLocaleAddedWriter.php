<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementLocaleAdded;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes PlacementLocaleAdded in the commit (PRD 5.7, 6.2 phase 7): the placement's released
 * PlacementLocale in the locale, with its slug below the node of the placement's released
 * generation, hidden and without a window, canonical as the plan says, in one statement, and the
 * placement at the context's version. The unique index on (node, locale, slug) among placements
 * that are not withdrawn is the backstop of invariant 15; the command read the slug as free, and
 * the commit checked it still is under its advisory lock. It returns no event: the placement's
 * placement.created, or the change that adds the locale, says the placement changed.
 */
#[Internal]
final readonly class PlacementLocaleAddedWriter implements MutationWriter
{
    /** The locale's row, from the placement and its released generation. */
    public const string INSERT = <<<'SQL'
        insert into placement_locales (placement_id, stage, locale, entry_id, node_id, slug, visibility, canonical, created_at)
        select p.id, g.stage, ?::text, p.entry_id, g.node_id, ?::text, ?::text, ?::boolean, ?::timestamptz
        from placements as p
        join placement_generations as g on g.placement_id = p.id and g.stage = 'released'
        where p.id = ?::uuid
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
        return PlacementLocaleAdded::class;
    }

    /**
     * @throws LogicException when the placement has no released generation
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementLocaleAdded) {
            throw new InvalidArgumentException(sprintf('The placement locale writer writes PlacementLocaleAdded, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $placement = $mutation->placement->toString();

        $added = $db->affectingStatement(self::INSERT, [
            $mutation->locale->value,
            $mutation->slug->value,
            Visibility::Hidden->value,
            $mutation->canonical,
            PlacementRows::timestamp($context->at),
            $placement,
        ]);

        if ($added !== 1) {
            throw new LogicException(sprintf('The placement %s has no released generation to add the locale %s to.', $placement, $mutation->locale->value));
        }

        $db->table('placements')->where('id', $placement)->update(['version' => $context->version->value]);

        return [];
    }
}
