<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementSlugSet;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementSlugChanged;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementSlugChangedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes PlacementSlugSet in the commit (PRD 5.9, 6.2 phase 7): the slug of the placement's
 * released PlacementLocale in the locale, in one statement that also reads the slug before and
 * leaves a withdrawn row as it is; the slug before into `slug_history` for the node and the locale,
 * pointing at the placement, replacing a row of that slug that pointed at another placement; the
 * history row of the new slug, when the placement is renamed back to a slug it had, so the live
 * lookup answers it; and the placement at the context's version. The visibility, the window and the
 * canonical flag are not touched: a rename is not a visibility change. It returns
 * placement.slug_changed.
 *
 * It runs as the app role under the call's actor context: `placement_locales_actor` and the
 * policies of `slug_history` take the rows only for a node the actor's regions reach (PRD 5.10),
 * and `placement_locales_slug_key` keeps the new slug unique among the placements that are not
 * withdrawn (invariant 15), behind the slug's advisory lock.
 */
#[Internal]
final readonly class PlacementSlugSetWriter implements MutationWriter
{
    /** The slug, with the slug before, the entry and the node. */
    public const string UPDATE = <<<'SQL'
        with old as (
            select placement_id, stage, locale, slug
            from placement_locales
            where placement_id = ?::uuid and stage = 'released' and locale = ? and visibility <> 'withdrawn'
            for no key update
        )
        update placement_locales as pl
        set slug = ?
        from old
        where pl.placement_id = old.placement_id and pl.stage = old.stage and pl.locale = old.locale
        returning pl.entry_id, pl.node_id, old.slug as previous
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
        return PlacementSlugSet::class;
    }

    /**
     * @throws LogicException when the placement has no locale to rename, or it is withdrawn
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementSlugSet) {
            throw new InvalidArgumentException(sprintf('The placement slug writer writes PlacementSlugSet, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $placement = $mutation->placement->toString();
        $locale = $mutation->locale->value;
        $rows = $db->select(self::UPDATE, [$placement, $locale, $mutation->slug->value], false);

        if (count($rows) !== 1) {
            throw new LogicException(sprintf('The placement %s has no locale %s whose slug can be set.', $placement, $locale));
        }

        $row = PlacementRows::object($rows[0]);
        $node = PlacementRows::text($row, 'node_id');

        $db->table('slug_history')->upsert([[
            'node_id' => $node,
            'locale' => $locale,
            'slug' => PlacementRows::text($row, 'previous'),
            'placement_id' => $placement,
            'changeset_id' => $context->changesetId->toString(),
            'created_at' => Timestamps::of($context->at),
        ]], ['node_id', 'locale', 'slug'], ['placement_id', 'changeset_id', 'created_at']);
        $db->table('slug_history')->where('node_id', $node)->where('locale', $locale)->where('slug', $mutation->slug->value)->delete();
        $db->table('placements')->where('id', $placement)->update(['version' => $context->version->value]);

        return [new PlacementSlugChanged($context->version->value, new PlacementSlugChangedV1(
            $mutation->placement,
            EntryId::fromString(PlacementRows::text($row, 'entry_id')),
            NodeId::fromString($node),
            $mutation->locale,
        ))];
    }
}
