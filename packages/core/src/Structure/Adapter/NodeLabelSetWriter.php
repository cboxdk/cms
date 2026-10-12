<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChangedV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes NodeLabelSet in the commit (PRD 5.8, 6.2 phase 7): the row in `node_labels` for the node
 * and the language, inserted or replaced, at the changeset's time, and the node's row set to the
 * context's version, because the node is the aggregate the label belongs to. It returns
 * node.label_changed.
 *
 * It runs as the app role under the call's actor context: `node_labels_write` and
 * `node_labels_change` take the label only for a node the actor's regions reach (PRD 5.10).
 */
#[Internal]
final readonly class NodeLabelSetWriter implements MutationWriter
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
        return NodeLabelSet::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof NodeLabelSet) {
            throw new InvalidArgumentException(sprintf('The node label writer writes NodeLabelSet, not %s.', $mutation::class));
        }

        $db = $this->connections->connection($this->connection);
        $at = Timestamps::of($context->at);

        $db->table('node_labels')->upsert(
            [[
                'node_id' => $mutation->node->toString(),
                'locale' => $mutation->locale->value,
                'label' => $mutation->label,
                'created_at' => $at,
                'updated_at' => $at,
            ]],
            ['node_id', 'locale'],
            ['label', 'updated_at'],
        );

        $changed = $db->table('nodes')->where('id', $mutation->node->toString())->update(['version' => $context->version->value]);

        if ($changed !== 1) {
            throw new LogicException(sprintf('The node %s is not a node the actor reaches, so its label was not set.', $mutation->node->toString()));
        }

        return [new NodeLabelChanged($context->version->value, new NodeLabelChangedV1($mutation->node, $mutation->locale))];
    }
}
