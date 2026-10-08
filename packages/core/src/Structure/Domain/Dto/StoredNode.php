<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;

/**
 * A node as a node command reads it (PRD 5.8), past the actor's regions: its id, its parent, its
 * kind, its path in the tree, its lifecycle state, its version, and whether the actor's regions
 * reach it. A node that exists but is not reached is read, so a command on it is unauthorized
 * instead of a conflict with an aggregate that looks absent (PRD 5.10).
 */
#[Internal]
final readonly class StoredNode
{
    public function __construct(
        public NodeId $id,
        public ?NodeId $parent,
        public NodeKind $kind,
        public NodePath $path,
        public NodeLifecycle $lifecycle,
        public AggregateVersion $version,
        public bool $reachable,
    ) {}

    public function archived(): bool
    {
        return $this->lifecycle === NodeLifecycle::Archived;
    }

    /**
     * The path a node created below this one has: this path with the child's own label below it.
     */
    public function childPath(string $label): NodePath
    {
        return new NodePath($this->path->value.'.'.$label);
    }

    /**
     * The first label of the node's path: the label of the root of the tree it is in, which is the
     * root node of its site (PRD 5.8), so a node belongs to the site whose root node has it.
     */
    public function rootLabel(): string
    {
        return explode('.', $this->path->value)[0];
    }
}
