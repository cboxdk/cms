<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One region of an actor's compiled grants (PRD 5.10): a node the actor may reach, with the
 * subtrees below it that it may not. Every exception lies strictly below the path, and no exception
 * is repeated or lies below another.
 */
#[Experimental]
final readonly class AccessRegion
{
    /**
     * @param  list<NodePath>  $exceptions
     *
     * @throws InvalidAccess
     */
    public function __construct(
        public NodePath $path,
        public array $exceptions = [],
    ) {
        foreach ($exceptions as $index => $exception) {
            if (! $path->isAbove($exception)) {
                throw InvalidAccess::exceptionOutside($path, $exception);
            }

            foreach ($exceptions as $otherIndex => $other) {
                if ($index !== $otherIndex && $other->contains($exception)) {
                    throw InvalidAccess::nestedExceptions($other, $exception);
                }
            }
        }
    }

    /**
     * Whether the region reaches the node: it is at or below the path and in no exception.
     */
    public function reaches(NodePath $node): bool
    {
        if (! $this->path->contains($node)) {
            return false;
        }

        return array_all($this->exceptions, fn (NodePath $exception): bool => ! $exception->contains($node));
    }
}
