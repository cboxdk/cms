<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\MountOverrideSet;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Dto\MountedEntry;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSet as MountOverrideSetEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSetV1;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes MountOverrideSet in the commit (PRD 5.8, 6.2 phase 7): hidden, the row of
 * `mount_overrides` for the mount and the entry, inserted or replaced, at the context's version
 * and time; not hidden, the row removed again, because an override row holds an exception, and a
 * mount without one shows the entry as its source does. The mount node then goes to the context's
 * version, so the exception is a change of the mount. It returns mount.override_set.
 *
 * Un-hiding an entry the source shows was planned as making content public or not from what the
 * action read; a window opened on a placement of the entry meanwhile does not change the mount's
 * version, so the writer reads the source once more at the changeset's time before it removes an
 * exception planned as not public, and rolls the commit back when the entry became visible: the
 * caller runs the command again and the action plans it as one that makes content public, which an
 * agent may not (invariant 18).
 *
 * It runs as the app role under the call's actor context: `mount_overrides_write`,
 * `mount_overrides_change` and `mount_overrides_remove` take the row only for a mount the actor's
 * regions reach, and `nodes_actor` the mount's version (PRD 5.10).
 */
#[Internal]
final readonly class MountOverrideSetWriter implements MutationWriter
{
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
        return MountOverrideSet::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof MountOverrideSet) {
            throw new InvalidArgumentException(sprintf('The mount override writer writes MountOverrideSet, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);

        if ($mutation->hidden) {
            $this->hide($db, $mutation, $context);
        } else {
            $this->show($db, $mutation, $context);
        }

        $changed = $db->table('nodes')->where('id', $mutation->mount->toString())->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The node %s is not a mount the actor reaches, so its exception was not set.', $mutation->mount->toString()));
        }

        return [new MountOverrideSetEvent($context->version->value, new MountOverrideSetV1($mutation->mount, $mutation->entry, $mutation->hidden))];
    }

    private function hide(ConnectionInterface $db, MountOverrideSet $mutation, MutationContext $context): void
    {
        $source = $db->table('nodes')->where('id', $mutation->mount->toString())->value('mount_source_id');

        if (! is_string($source)) {
            throw new LogicException(sprintf('The node %s is not a mount the actor reaches, so its exception was not set.', $mutation->mount->toString()));
        }

        $db->table('mount_overrides')->upsert(
            [[
                'mount_node_id' => $mutation->mount->toString(),
                'source_node_id' => $source,
                'entry_id' => $mutation->entry->toString(),
                'hidden' => true,
                'priority_cap' => null,
                'version' => $context->version->value,
                'created_at' => Timestamps::of($context->at),
            ]],
            ['mount_node_id', 'entry_id'],
            ['hidden', 'version'],
        );
    }

    private function show(ConnectionInterface $db, MountOverrideSet $mutation, MutationContext $context): void
    {
        if (! $mutation->visible) {
            $source = $db->table('nodes')->where('id', $mutation->mount->toString())->value('mount_source_id');
            $mounted = is_string($source)
                ? new PostgresNodeReader($this->connections, $this->connection)->mountedEntry(NodeId::fromString($source), $mutation->entry, $context->at)
                : null;

            if ($mounted instanceof MountedEntry && $mounted->visible instanceof PlacementId) {
                throw new LogicException(sprintf(
                    'The placement %s of the entry %s became visible while mount.set_override ran, and taking the exception off the mount %s shows it publicly only when planned so (invariant 18).',
                    $mounted->visible->toString(),
                    $mutation->entry->toString(),
                    $mutation->mount->toString(),
                ));
            }
        }

        $removed = $db->table('mount_overrides')
            ->where('mount_node_id', $mutation->mount->toString())
            ->where('entry_id', $mutation->entry->toString())
            ->delete();

        if ($removed !== 1) {
            throw new LogicException(sprintf('The mount %s holds no exception for the entry %s the actor reaches, so none was taken off.', $mutation->mount->toString(), $mutation->entry->toString()));
        }
    }
}
