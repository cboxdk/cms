<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a resolution of (host, locale, path) ended (PRD 5.9), named by the step that stopped it:
 *
 * - UnknownHost: no configured site has the host (PRD 8.10 point 7).
 * - UnknownSite: the host's site is configured, but no site has its handle.
 * - LocaleNotPublished: the site does not publish in the locale.
 * - NoRoute: no route of the site in the locale is a prefix of the path.
 * - NoSlug: what is left of the path after the route is not one slug, or is nothing.
 * - NoPlacement: no placement the reader can read has the slug below the node in the locale.
 * - NotRoutable: the entry's type has no URLs (its blueprint says routable: false).
 * - NotVisible: the precedence of PRD 6.6 blocks the placement at the time.
 * - Resolved: the placement is visible.
 */
#[Experimental]
enum ResolveOutcome: string
{
    case UnknownHost = 'unknown_host';
    case UnknownSite = 'unknown_site';
    case LocaleNotPublished = 'locale_not_published';
    case NoRoute = 'no_route';
    case NoSlug = 'no_slug';
    case NoPlacement = 'no_placement';
    case NotRoutable = 'not_routable';
    case NotVisible = 'not_visible';
    case Resolved = 'resolved';
}
