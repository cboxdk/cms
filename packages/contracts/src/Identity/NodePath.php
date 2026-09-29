<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The path of a node in the tree, as the ltree the RLS policies test against (PRD 5.10): labels of
 * letters, digits, underscores and hyphens, 1 to 1000 characters each, joined by dots.
 */
#[Experimental]
final readonly class NodePath
{
    private const string PATTERN = '/\A[A-Za-z0-9_-]{1,1000}(?:\.[A-Za-z0-9_-]{1,1000})*\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidAccess::path($value);
        }
    }

    /**
     * Whether $other is this node or below it.
     */
    public function contains(self $other): bool
    {
        return $other->value === $this->value || str_starts_with($other->value, $this->value.'.');
    }

    /**
     * Whether $other is below this node, and not this node itself.
     */
    public function isAbove(self $other): bool
    {
        return $other->value !== $this->value && $this->contains($other);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
