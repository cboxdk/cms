<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated as PlacementCreatedEvent;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreatedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes PlacementCreated in the commit (PRD 5.7, 6.2 phase 7): the placement's identity in
 * `placements` at the context's version and its released generation below the node in
 * `placement_generations`. M1 has no placement workflow, so a placement command writes the
 * released stage directly (PRD 4.1). It returns placement.created. It runs as the app role under
 * the call's actor context, so the node must be one the actor's regions reach (PRD 5.10).
 */
#[Internal]
final readonly class PlacementCreatedWriter implements MutationWriter
{
    /** The stage the placement commands write in M1. */
    public const string STAGE = 'released';

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
        return PlacementCreated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof PlacementCreated) {
            throw new InvalidArgumentException(sprintf('The placement writer writes PlacementCreated, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $at = PlacementRows::timestamp($context->at);

        $db->table('placements')->insert([
            'id' => $mutation->placement->toString(),
            'entry_id' => $mutation->entry->toString(),
            'version' => $context->version->value,
            'created_at' => $at,
        ]);

        $db->table('placement_generations')->insert([
            'placement_id' => $mutation->placement->toString(),
            'stage' => self::STAGE,
            'node_id' => $mutation->node->toString(),
            'created_at' => $at,
        ]);

        return [new PlacementCreatedEvent($context->version->value, new PlacementCreatedV1($mutation->placement, $mutation->entry, $mutation->node, $mutation->site))];
    }
}
