<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Domain;

use InvalidArgumentException;

/**
 * Where `composer services:up` and `services:down` run: the root of the checkout, and the root of
 * the main checkout of its repository, whose compose.yaml the shared services always come from.
 * The two are the same directory unless the checkout is a linked worktree. Both are absolute real
 * paths.
 */
final readonly class Checkout
{
    public function __construct(
        public string $root,
        public string $mainRoot,
    ) {
        foreach (['root' => $root, 'main root' => $mainRoot] as $name => $path) {
            if (! str_starts_with($path, '/') || ($path !== '/' && str_ends_with($path, '/'))) {
                throw new InvalidArgumentException("The {$name} of a checkout is an absolute path without a trailing slash, not [{$path}].");
            }
        }
    }

    public function isLinkedWorktree(): bool
    {
        return $this->root !== $this->mainRoot;
    }
}
