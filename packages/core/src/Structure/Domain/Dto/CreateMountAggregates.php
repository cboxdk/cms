<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What mount.create read (PRD 6.2 phase 1): the node with the command's id, which it expects not
 * to exist; the parent it goes below, null when no node has that id; the source whose placements
 * it shows, null when none has that id; and every ancestor of the parent, the root first.
 *
 * Every node read is an aggregate the kernel checks at commit, each once, however many times the
 * command names it: a parent, a source or an ancestor changed meanwhile is version_conflict. The
 * ancestors are read so the commit locks the path the mount lands under: a node.move that puts
 * the source above the parent meanwhile changes the version of the moved node, which is the
 * source or one of the ancestors, so the create is version_conflict and not a mount below its own
 * source.
 */
#[Internal]
final readonly class CreateMountAggregates implements Aggregates
{
    /**
     * @param  list<StoredNode>  $ancestors  of the parent, the root first
     */
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public NodeId $parent,
        public ?StoredNode $storedParent,
        public NodeId $source,
        public ?StoredNode $storedSource,
        public array $ancestors,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [$this->node->aggregateKey() => new ReadVersion($this->node, $this->current?->version)];
        $reads[$this->parent->aggregateKey()] ??= new ReadVersion($this->parent, $this->storedParent?->version);
        $reads[$this->source->aggregateKey()] ??= new ReadVersion($this->source, $this->storedSource?->version);

        foreach ($this->ancestors as $ancestor) {
            $reads[$ancestor->id->aggregateKey()] ??= new ReadVersion($ancestor->id, $ancestor->version);
        }

        return new ReadVersions(...array_values($reads));
    }

    /**
     * Whether the source is the parent or above it, so the mount would sit below its own source.
     */
    public function sourceAbove(): bool
    {
        return $this->storedParent instanceof StoredNode
            && $this->storedSource instanceof StoredNode
            && $this->storedSource->path->contains($this->storedParent->path);
    }

    /**
     * The parent, in every locale: a mount below it is created with the rights on it (PRD 5.10).
     * Anywhere when the parent read as absent or outside the actor's regions, which the refusals
     * answer with their own codes.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->storedParent instanceof StoredNode && $this->storedParent->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->parent))
            : AuthorizationScope::anywhere();
    }
}
