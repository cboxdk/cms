<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * One place a command acts on, which the kernel's authorization holds the actor's grants to (PRD
 * 5.10): a node, and the locale the command acts in there, or null for a command that acts in
 * every locale, such as one on an entry's shared variant. A grant whose locale set is limited holds
 * only for a target in one of its locales.
 */
#[Experimental]
final readonly class AuthorizationTarget
{
    public function __construct(
        public NodeId $node,
        public ?Locale $locale = null,
    ) {}

    public function equals(self $other): bool
    {
        if (! $this->node->equals($other->node)) {
            return false;
        }

        return $this->locale instanceof Locale && $other->locale instanceof Locale
            ? $this->locale->equals($other->locale)
            : $this->locale === $other->locale;
    }
}
