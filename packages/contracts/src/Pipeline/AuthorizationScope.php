<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where the kernel authorizes a command (PRD 5.10, 6.2 phase 2), which the action's Aggregates
 * name from what resolve() read: the nodes it acts on, each with its locale. Content rights are
 * held to the entry's home, placement rights to the placement's node. A command is allowed only
 * when a role of the actor that has the command's permission reaches every target.
 *
 * A scope without targets, anywhere(), is for a command whose aggregates name no node, such as one
 * on an actor, or one whose aggregate read as absent: the kernel then needs a role with the
 * permission that reaches some node, and leaves the absent aggregate to the later phases, which
 * reject it with their own codes.
 */
#[Experimental]
final readonly class AuthorizationScope
{
    /**
     * @param  list<AuthorizationTarget>  $targets  each once, in the order first given
     */
    private function __construct(public array $targets) {}

    public static function on(AuthorizationTarget $first, AuthorizationTarget ...$more): self
    {
        $targets = [];

        foreach ([$first, ...$more] as $target) {
            if (! array_any($targets, static fn (AuthorizationTarget $held): bool => $held->equals($target))) {
                $targets[] = $target;
            }
        }

        return new self($targets);
    }

    public static function anywhere(): self
    {
        return new self([]);
    }

    public function isAnywhere(): bool
    {
        return $this->targets === [];
    }
}
