<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes PlacementCanonicalSet in the commit (PRD 5.7, invariant 14, 6.2 phase 7): the canonical
 * flag of the placement's released PlacementLocale in the locale, and the placement at the
 * context's version, through `cms_placement_set_canonical`. The canonical placement is one across
 * all of an entry's placements, and the placement that loses or takes the flag can be below a node
 * the actor's regions do not reach, so the function writes it as the owner, only in the
 * transaction of the changeset by the context's actor. The unique index on the canonical flag per
 * entry and locale is the backstop of "at most one". It returns no event.
 */
#[Internal]
final readonly class PlacementCanonicalSetWriter implements MutationWriter
{
    public const string SET = 'select cms_placement_set_canonical(?::uuid, ?, ?::boolean, ?::bigint, ?::uuid)';

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
        return PlacementCanonicalSet::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementCanonicalSet) {
            throw new InvalidArgumentException(sprintf('The canonical writer writes PlacementCanonicalSet, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::SET, [
            $mutation->placement->toString(),
            $mutation->locale->value,
            $mutation->canonical,
            $context->version->value,
            $context->changesetId->toString(),
        ]);

        return [];
    }
}
