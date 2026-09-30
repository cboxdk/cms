<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * A slug below a node in a locale (PRD 5.9, invariant 15), as an aggregate a command reads so two
 * commands that claim the same slug commit one after the other: it exists, at version 1, when a
 * placement that is not withdrawn has the slug there, and is absent otherwise. A command that gives
 * a placement the slug reads it as absent, and the commit, which locks an aggregate read as absent
 * with an advisory lock first, finds it taken when another command gave the slug away meanwhile,
 * which is version_conflict instead of a unique violation.
 */
#[Internal]
final readonly class PlacementSlugRef implements AggregateRef
{
    public const string KIND = 'placement_slug';

    public function __construct(
        public NodeId $node,
        public Locale $locale,
        public Slug $slug,
    ) {}

    /**
     * "placement_slug:", the node, the locale and the slug, separated by colons. The node's UUID
     * and the locale hold no colon, so the rest is the slug, and two slugs never share a key.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return sprintf('%s:%s:%s:%s', self::KIND, $this->node->toString(), $this->locale->value, $this->slug->value);
    }
}
