<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;

/**
 * Compiles an actor's grants into its AccessContext (PRD 5.10, 12.2): the access regions that row
 * level security tests a node's path against, and the classification access.
 *
 * A node is reached when one of the actor's roles reaches it in one locale. A role reaches a node
 * in a locale when the grant of that role nearest above the node, or on it, among the grants that
 * hold in that locale, allows: a deny beats the allow it inherits, and the most specific grant
 * wins, so an allow below a deny reaches its subtree again. Where a role has an allow and a deny
 * on the same node in a locale, the deny wins. A grant with a locale set holds only in its locales,
 * so a deny for one locale keeps a node reached through the other locales; the kernel checks the
 * locale of a command against the grants themselves.
 *
 * The regions are the fewest that say the same: a region for each node reached whose nearest
 * decided node above is not reached, with the nodes below it that are not reached, and whose
 * nearest decided node above is reached, as its exceptions. A region can then lie inside another's
 * exception, and no node is in two regions.
 *
 * The classification access is the one that holds on every node reached: on each node the highest
 * ceiling among the roles that reach it, and the lowest of those over the nodes, capped by the
 * credential's ceiling; with no node reached it is public. One context carries one access, so a
 * role with a high ceiling on a few nodes never lifts the fields of the others.
 *
 * An actor that acts on behalf of others (PRD 5.16, 2.31, 22) gets the intersection of its own
 * compiled context and the compiled context of every actor of its chain (intersect()): a node is
 * reached only when every context reaches it, and the classification access is the lowest of
 * theirs, still capped by the credential's ceiling.
 */
#[Internal]
final readonly class AccessCompiler
{
    /**
     * @param  list<Grant>  $grants
     */
    public function compile(ActorPrincipal $principal, array $grants): AccessContext
    {
        $decisions = $this->decisions($grants);
        $paths = $this->paths($grants);
        $marks = [];

        foreach ($paths as $key => $path) {
            $reached = $this->reached($decisions, $path);

            if ($reached !== $this->implied($marks, $paths, $path)) {
                $marks[$key] = $reached;
            }
        }

        return new AccessContext(
            $principal,
            $this->regions($marks, $paths),
            $this->classification($grants, $decisions, $paths)->atMost($principal->classificationCeiling()),
        );
    }

    /**
     * The intersection of contexts compiled for one call (PRD 5.16): the context of the actor and
     * that of every actor it acts on behalf of, each compiled from that actor's own grants. A node
     * is reached only when every context reaches it, and the classification access is the lowest of
     * theirs, capped by the principal's ceiling. The regions are again the fewest that say the same.
     *
     * Each context's reach is decided, for any node, by the deepest of its region paths and
     * exceptions at or above the node, so the intersection's reach is decided by the deepest of all
     * contexts' paths at or above it, and is marked on those paths as compile() marks the grants'.
     */
    public function intersect(ActorPrincipal $principal, AccessContext $first, AccessContext ...$others): AccessContext
    {
        $contexts = [$first, ...array_values($others)];
        $paths = [];
        $classification = $principal->classificationCeiling();

        foreach ($contexts as $context) {
            $classification = $classification->atMost($context->classificationAccess);

            foreach ($context->regions as $region) {
                $paths[$region->path->value] = $region->path;

                foreach ($region->exceptions as $exception) {
                    $paths[$exception->value] = $exception;
                }
            }
        }

        $paths = $this->sorted($paths);
        $marks = [];

        foreach ($paths as $key => $path) {
            $reached = array_all($contexts, static fn (AccessContext $context): bool => $context->reaches($path));

            if ($reached !== $this->implied($marks, $paths, $path)) {
                $marks[$key] = $reached;
            }
        }

        return new AccessContext($principal, $this->regions($marks, $paths), $classification);
    }

    /**
     * The decisions of each role in each locale, by path: the grants' effects, a deny winning over
     * an allow on the same path. The locale key '' stands for every locale no grant names.
     *
     * @param  list<Grant>  $grants
     * @return array<string, array<string, array<string, GrantEffect>>> role, locale, path
     */
    private function decisions(array $grants): array
    {
        $locales = ['' => null];

        foreach ($grants as $grant) {
            foreach ($grant->locales ?? [] as $locale) {
                $locales[$locale->value] = $locale;
            }
        }

        $decisions = [];

        foreach ($grants as $grant) {
            $role = $grant->role->toString();

            foreach ($locales as $key => $locale) {
                if (! $grant->holdsIn($locale)) {
                    continue;
                }

                $held = $decisions[$role][$key][$grant->node->value] ?? null;
                $decisions[$role][$key][$grant->node->value] = $held === GrantEffect::Deny ? GrantEffect::Deny : $grant->effect;
            }
        }

        return $decisions;
    }

    /**
     * Every path a grant names, from the shallowest to the deepest.
     *
     * @param  list<Grant>  $grants
     * @return array<string, NodePath>
     */
    private function paths(array $grants): array
    {
        $paths = [];

        foreach ($grants as $grant) {
            $paths[$grant->node->value] = $grant->node;
        }

        return $this->sorted($paths);
    }

    /**
     * The paths from the shallowest to the deepest.
     *
     * @param  array<string, NodePath>  $paths
     * @return array<string, NodePath>
     */
    private function sorted(array $paths): array
    {
        uksort($paths, static fn (string $a, string $b): int => [substr_count($a, '.'), $a] <=> [substr_count($b, '.'), $b]);

        return $paths;
    }

    /**
     * Whether a role reaches the node in a locale.
     *
     * @param  array<string, array<string, array<string, GrantEffect>>>  $decisions
     */
    private function reached(array $decisions, NodePath $node): bool
    {
        $reached = false;

        foreach ($decisions as $byLocale) {
            $reached = $reached || $this->roleReaches($byLocale, $node);
        }

        return $reached;
    }

    /**
     * The effect of the decision nearest above the node, or on it, or null without one.
     *
     * @param  array<string, GrantEffect>  $byPath
     */
    private function nearest(array $byPath, NodePath $node): ?GrantEffect
    {
        $nearest = null;
        $depth = -1;

        foreach ($byPath as $path => $effect) {
            $above = new NodePath($path);

            if ($above->contains($node) && substr_count($path, '.') > $depth) {
                $nearest = $effect;
                $depth = substr_count($path, '.');
            }
        }

        return $nearest;
    }

    /**
     * Whether the marks above the node, not on it, say it is reached: the nearest mark's value, or
     * false without one.
     *
     * @param  array<string, bool>  $marks
     * @param  array<string, NodePath>  $paths
     */
    private function implied(array $marks, array $paths, NodePath $node): bool
    {
        $implied = false;
        $depth = -1;

        foreach ($marks as $key => $reached) {
            if ($paths[$key]->isAbove($node) && substr_count($key, '.') > $depth) {
                $implied = $reached;
                $depth = substr_count($key, '.');
            }
        }

        return $implied;
    }

    /**
     * A region for each reached mark, with the unreached marks whose nearest mark above is it as
     * its exceptions, sorted by path.
     *
     * @param  array<string, bool>  $marks
     * @param  array<string, NodePath>  $paths
     * @return list<AccessRegion>
     */
    private function regions(array $marks, array $paths): array
    {
        ksort($marks, SORT_STRING);
        $regions = [];

        foreach ($marks as $key => $reached) {
            if (! $reached) {
                continue;
            }

            $exceptions = [];

            foreach ($marks as $below => $reachedBelow) {
                if (! $reachedBelow && $this->nearestMarkAbove($marks, $paths, $paths[$below]) === $key) {
                    $exceptions[] = $paths[$below];
                }
            }

            $regions[] = new AccessRegion($paths[$key], $exceptions);
        }

        return $regions;
    }

    /**
     * The key of the mark nearest above the node, not on it, or null without one.
     *
     * @param  array<string, bool>  $marks
     * @param  array<string, NodePath>  $paths
     */
    private function nearestMarkAbove(array $marks, array $paths, NodePath $node): ?string
    {
        $nearest = null;

        foreach (array_keys($marks) as $key) {
            if ($paths[$key]->isAbove($node) && ($nearest === null || substr_count($key, '.') > substr_count($nearest, '.'))) {
                $nearest = $key;
            }
        }

        return $nearest;
    }

    /**
     * The classification access that holds on every node the actor reaches: for each node, the
     * highest ceiling among the roles that reach it in some locale, and of those the lowest. A role
     * whose ceiling is high on one node therefore never raises the access on a node it does not
     * reach (PRD 5.10, 12.2). The decisions change only at the paths the grants name, so a node
     * below such a path and above no other gets the same roles as the path, and the paths are the
     * nodes to look at. With no node reached the access is public.
     *
     * @param  list<Grant>  $grants
     * @param  array<string, array<string, array<string, GrantEffect>>>  $decisions
     * @param  array<string, NodePath>  $paths
     */
    private function classification(array $grants, array $decisions, array $paths): ClassificationAccess
    {
        $ceilings = [];

        foreach ($grants as $grant) {
            $ceilings[$grant->role->toString()] = $grant->roleCeiling;
        }

        $lowest = null;

        foreach ($paths as $path) {
            $highest = null;

            foreach ($decisions as $role => $byLocale) {
                if (! $this->roleReaches($byLocale, $path)) {
                    continue;
                }

                if ($highest === null || $ceilings[$role]->rank() > $highest->rank()) {
                    $highest = $ceilings[$role];
                }
            }

            if ($highest !== null && ($lowest === null || $highest->rank() < $lowest->rank())) {
                $lowest = $highest;
            }
        }

        return $lowest ?? ClassificationAccess::Public;
    }

    /**
     * Whether one role reaches the node in some locale.
     *
     * @param  array<string, array<string, GrantEffect>>  $byLocale
     */
    private function roleReaches(array $byLocale, NodePath $node): bool
    {
        $reaches = false;

        foreach ($byLocale as $byPath) {
            $reaches = $reaches || $this->nearest($byPath, $node) === GrantEffect::Allow;
        }

        return $reaches;
    }
}
