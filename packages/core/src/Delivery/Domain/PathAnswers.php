<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use DateTimeImmutable;

/**
 * What the delivery API answers for a resolution (PRD 6.6, 8.10): the code of the error catalog it
 * ends with, the content keys a cache of the answer depends on, until when the answer holds, and
 * whether the edge may serve it stale.
 *
 * - A resolved path is answered with its record; every other outcome with a problem. An unknown
 *   host is host_not_configured (421, point 7). What was withdrawn, the variant or the placement,
 *   and an entry that was tombstoned or purged, is path_gone (410): withdrawn is sticky
 *   (invariant 7). Everything else is path_not_found (404): nothing answers the path now, and a
 *   publication, a placement or an opening window can change that.
 * - The keys are the read's `e-{entry}` and `n-{node}` and, once a route was matched, `n-{node}` of
 *   the node the slug was looked up under, so a 404 and a 410 are purged too when what they depend
 *   on changes (point 10).
 * - The answer holds until the placement's window next changes the decision, its end for a visible
 *   placement and its start for one that has not opened (invariant 17), the same cap for 200, 404
 *   and 410.
 * - Only a resolved path whose window never ends may be served stale; before a removal, and for
 *   every problem, no stale directive is sent (PRD 8.12 points 3 and 4).
 */
#[Internal]
final readonly class PathAnswers
{
    /**
     * The code of the catalog the resolution ends with, or null when it resolved.
     */
    public static function code(PathExplanation $explanation): ?ErrorCode
    {
        return match ($explanation->outcome) {
            ResolveOutcome::Resolved => null,
            ResolveOutcome::UnknownHost => ErrorCode::HostNotConfigured,
            ResolveOutcome::NotVisible => self::gone($explanation->visibility) ? ErrorCode::PathGone : ErrorCode::PathNotFound,
            default => ErrorCode::PathNotFound,
        };
    }

    /**
     * The concrete cause, for the problem's detail.
     */
    public static function detail(PathExplanation $explanation): string
    {
        $asked = sprintf('%s in %s at %s', $explanation->route?->path->value ?? 'the path', $explanation->site->locale->value, $explanation->site->host->value);

        return match ($explanation->outcome) {
            ResolveOutcome::Resolved => sprintf('%s resolved.', $asked),
            ResolveOutcome::UnknownHost => sprintf('No configured site is served at %s.', $explanation->site->host->value),
            ResolveOutcome::UnknownSite => sprintf('The site configured for %s does not exist.', $explanation->site->host->value),
            ResolveOutcome::LocaleNotPublished => sprintf('The site at %s does not publish in %s.', $explanation->site->host->value, $explanation->site->locale->value),
            ResolveOutcome::NoRoute, ResolveOutcome::NoSlug, ResolveOutcome::NoPlacement => sprintf('Nothing is placed at %s.', $asked),
            ResolveOutcome::NotRoutable => sprintf('What is placed at %s has no URL.', $asked),
            ResolveOutcome::NotVisible => sprintf('What is placed at %s is not shown: %s.', $asked, $explanation->visibility?->decision->value ?? 'not visible'),
        };
    }

    /**
     * The content keys of the answer: the read's, and the node a slug was looked up under.
     *
     * @param  list<DependencyKey>  $read
     * @return list<DependencyKey>
     */
    public static function contentKeys(PathExplanation $explanation, array $read): array
    {
        return $explanation->placement instanceof PlacementStep
            ? [...$read, DependencyKey::node($explanation->placement->lookedUnder)]
            : $read;
    }

    /**
     * When the answer stops holding because of the placement's window, or null when no window
     * changes it.
     */
    public static function validUntil(PathExplanation $explanation): ?DateTimeImmutable
    {
        return $explanation->visibility?->validUntil;
    }

    /**
     * Whether the edge may serve the answer stale: a resolved path whose window never ends.
     */
    public static function mayBeStale(PathExplanation $explanation): bool
    {
        return $explanation->outcome === ResolveOutcome::Resolved && ! self::validUntil($explanation) instanceof DateTimeImmutable;
    }

    private static function gone(?VisibilityStep $visibility): bool
    {
        return match ($visibility?->decision) {
            VisibilityDecision::VariantWithdrawn, VisibilityDecision::PlacementWithdrawn => true,
            VisibilityDecision::EntryNotActive => in_array($visibility->lifecycle, [EntryLifecycle::Tombstoned, EntryLifecycle::Purged], true),
            default => false,
        };
    }
}
