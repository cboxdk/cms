<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\MountOverrideSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\Commands\SetMountOverride;
use Cbox\Cms\Core\Structure\Domain\Dto\MountedEntry;
use Cbox\Cms\Core\Structure\Domain\Dto\SetMountOverrideAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredMountOverride;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Override;

/**
 * The write action of mount.set_override (PRD 5.8, 5.10, 6.2, 6.6 rung 6, invariant 18), exposed
 * on every surface. resolve() reads the mount node past the actor's regions, the override the
 * mount has for the entry, and, when the command un-hides, what the mount's source shows of the
 * entry at the Clock's time or later; refusals() refuses what those reads rule out; plan() sets or
 * removes the exception.
 *
 * It is decided on the mount's node (PRD 5.10): a mount that exists but is not reached by the
 * actor's regions is unauthorized. A node that is not a mount, an archived mount, an entry that
 * does not exist, hiding an entry the mount hides already and un-hiding one it does not hide,
 * which would change nothing, are validation_failed. Hiding never makes content public; un-hiding an entry its source shows now
 * or later does, so the plan's MountOverrideSet makes content public exactly then, and the
 * pipeline refuses it from an agent or a token with agent_visibility_forbidden.
 *
 * @implements WriteAction<SetMountOverride, SetMountOverrideAggregates>
 * @implements RefusesCommand<SetMountOverride, SetMountOverrideAggregates>
 */
#[Action(handles: SetMountOverride::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class SetMountOverrideAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private NodeReader $nodes,
        private Clock $clock,
    ) {}

    /**
     * @param  SetMountOverride  $command
     */
    #[Override]
    public function resolve(Command $command): SetMountOverrideAggregates
    {
        $at = $this->clock->now();
        $mount = $this->nodes->node($command->mount);
        $source = $mount instanceof StoredNode && $mount->kind === NodeKind::Mount ? $mount->mountSource : null;

        return new SetMountOverrideAggregates(
            $command->mount,
            $mount,
            $command->entry,
            $mount instanceof StoredNode ? $this->nodes->mountOverride($command->mount, $command->entry) : null,
            $source instanceof NodeId ? $this->nodes->mountedEntry($source, $command->entry, $at) : null,
            $at,
        );
    }

    /**
     * @param  SetMountOverride  $command
     * @param  SetMountOverrideAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $mount = $aggregates->current;

        if (! $mount instanceof StoredNode) {
            return [];
        }

        if (! $mount->reachable) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('mount'), sprintf(
                'The node %s is not reached by the actor\'s grants, and an exception to a mount is decided on the mount\'s node (PRD 5.10).',
                $command->mount->toString(),
            ))];
        }

        if ($mount->kind !== NodeKind::Mount) {
            return [$this->invalid('mount', sprintf('The node %s is not a mount; an exception is set on the mount that shows the entry (PRD 5.8).', $command->mount->toString()))];
        }

        $refusals = [];

        if ($mount->archived()) {
            $refusals[] = $this->invalid('mount', sprintf('The mount %s is archived, and archived structure takes no exception (PRD 6.4).', $command->mount->toString()));
        }

        if (! $aggregates->mounted instanceof MountedEntry) {
            $refusals[] = $this->invalid('entry', sprintf('No entry %s exists for the mount %s to hide or show.', $command->entry->toString(), $command->mount->toString()));
        } elseif ($command->hidden && $aggregates->override instanceof StoredMountOverride) {
            $refusals[] = $this->invalid('hidden', sprintf('The mount %s hides the entry %s already.', $command->mount->toString(), $command->entry->toString()));
        } elseif (! $command->hidden && ! $aggregates->override instanceof StoredMountOverride) {
            $refusals[] = $this->invalid('hidden', sprintf('The mount %s does not hide the entry %s, so there is no exception to take off.', $command->mount->toString(), $command->entry->toString()));
        }

        return $refusals;
    }

    /**
     * @param  SetMountOverride  $command
     * @param  SetMountOverrideAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new MountOverrideSet(
            $command->mount,
            $command->entry,
            $command->hidden,
            $aggregates->mounted?->visible instanceof PlacementId,
        ));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
