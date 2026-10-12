<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Fragments\Fakes;

use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use Override;

/**
 * The SubtreeNodes of the invalidation tests: the nodes a test adds with their paths, paged as
 * PostgresSubtreeNodes pages them. SubtreeNodesBehaviour holds it to that adapter.
 */
final class FakeSubtreeNodes implements SubtreeNodes
{
    /** @var array<string, NodePath> by the node's id */
    public array $nodes = [];

    /** @var list<string> each page read, as "<node> <after or -> <limit>" */
    public array $reads = [];

    /**
     * A node on the path, which is its parent's with its own label, the id without hyphens, below.
     */
    public function withNode(NodeId $node, ?NodeId $parent = null): NodeId
    {
        $label = str_replace('-', '', $node->toString());
        $above = $parent instanceof NodeId ? $this->nodes[$parent->toString()] ?? null : null;
        $this->nodes[$node->toString()] = new NodePath($above instanceof NodePath ? $above->value.'.'.$label : $label);

        return $node;
    }

    #[Override]
    public function below(NodeId $node, ?NodeId $after, int $limit): array
    {
        $this->reads[] = $node->toString().' '.($after?->toString() ?? '-').' '.$limit;
        $path = $this->nodes[$node->toString()] ?? null;

        if (! $path instanceof NodePath) {
            return [];
        }

        $ids = [];

        foreach ($this->nodes as $id => $other) {
            if ($path->contains($other) && (! $after instanceof NodeId || strcmp($id, $after->toString()) > 0)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_map(NodeId::fromString(...), array_slice($ids, 0, $limit));
    }
}
