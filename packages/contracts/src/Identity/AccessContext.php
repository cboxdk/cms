<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a command or a read may reach (PRD 5.10, 6.2, 12.2): the principal, the access regions of
 * its compiled grants and its classification access. The kernel computes it once per call from the
 * verified principal, and the pipelines consume it: the regions become the RLS context and the
 * query builder's predicates, and the read pipeline removes every field classified above the
 * classification access.
 *
 * The regions are disjoint: no node is reached by two of them. A region lies at or below another
 * only inside one of the other's exceptions, where a more specific allow sits below a deny (PRD
 * 5.10). The classification access never exceeds the principal's ceiling. An actor that is not
 * active has no regions (PRD 5.16).
 */
#[Experimental]
final readonly class AccessContext
{
    /**
     * @param  list<AccessRegion>  $regions
     *
     * @throws InvalidAccess
     */
    public function __construct(
        public Principal $principal,
        public array $regions,
        public ClassificationAccess $classificationAccess,
    ) {
        $ceiling = $principal->classificationCeiling();

        if (! $ceiling->allows($classificationAccess)) {
            throw InvalidAccess::aboveCeiling($classificationAccess, $ceiling);
        }

        foreach ($regions as $index => $region) {
            foreach ($regions as $otherIndex => $other) {
                if ($index !== $otherIndex && $other->path->contains($region->path) && ! $other->excludes($region->path)) {
                    throw InvalidAccess::overlappingRegions($other->path, $region->path);
                }
            }
        }
    }

    /**
     * The context of a call without a credential: the anonymous principal, no regions and public
     * classification access (invariant 25).
     */
    public static function anonymous(): self
    {
        return new self(new AnonymousPrincipal, [], ClassificationAccess::Public);
    }

    /**
     * Whether one of the regions reaches the node.
     */
    public function reaches(NodePath $node): bool
    {
        return array_any($this->regions, fn (AccessRegion $region): bool => $region->reaches($node));
    }
}
