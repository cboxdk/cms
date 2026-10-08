<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A node is created below another (PRD 5.8): the new node, its parent, what kind of structure it
 * is, and its path in the tree, the parent's path with the node's own label below it. A node is
 * structure, never an article: a section, a page, a list or a storage folder. KINDS leaves out the
 * site root, which comes with a site's registration, and the mount, which names the node whose
 * placements it shows. The kind is text here, because its enum lives in the core module, which the
 * contracts never depend on.
 *
 * The path's last label is the node's id as 32 hex digits, and a label above it is its parent's, as
 * the `nodes` table requires, so no writer can store a path that puts the node in another subtree
 * than the one the command was authorized on (PRD 5.10).
 */
#[Experimental]
final readonly class NodeCreated implements Mutation
{
    /** @var list<string> the kinds of node this mutation creates: all but a site root and a mount */
    public const array KINDS = ['section', 'page', 'list', 'storage'];

    /**
     * @throws InvalidMutation for a kind it does not create, or a path that is not below another
     *                         node and ending in the node's own label
     */
    public function __construct(
        public NodeId $node,
        public NodeId $parent,
        public string $kind,
        public NodePath $path,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw InvalidMutation::nodeKind($node, $kind);
        }

        if (! str_contains($path->value, '.') || ! str_ends_with($path->value, '.'.self::label($node))) {
            throw InvalidMutation::nodePath($node, $path);
        }
    }

    /**
     * The ltree label of a node: its id without the hyphens, as the `nodes` table requires.
     */
    public static function label(NodeId $node): string
    {
        return str_replace('-', '', $node->toString());
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }
}
