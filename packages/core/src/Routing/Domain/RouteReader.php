<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\OwnPlacement;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;

/**
 * The reads of path.resolve (PRD 5.9), under the read's actor context, so row level security
 * decides what the reader sees (PRD 5.10): the anonymous context reads the structure and what is
 * public, an actor also what its regions reach. Each read is one statement, whatever the number of
 * routes, nodes or placements, so a resolution costs the same queries at any size (GUARDRAILS 4.1).
 */
#[Internal]
interface RouteReader
{
    /**
     * The site with the handle, whether it publishes in the locale, and the longest of its routes
     * in the locale that is a prefix of the path, with the node it reaches; null when no site has
     * the handle.
     */
    public function route(SiteHandle $site, Locale $locale, RequestPath $path): ?SiteRoute;

    /**
     * The released stage of the placement with the slug below the node in the locale: the one that
     * is not withdrawn when there is one, else a withdrawn one, the lowest id first; null when the
     * reader can read none. Looked up through a mount, the match also says whether the mount's
     * site hides the entry there (PRD 5.8), in the same statement.
     */
    public function placement(NodeId $node, Locale $locale, Slug $slug, ?NodeId $mount = null): ?PlacementMatch;

    /**
     * The canonical placement of the entry in the locale, in its released stage, with the route of
     * its node in the site whose tree holds the node; null when the reader can read none.
     */
    public function canonical(EntryId $entry, Locale $locale): ?CanonicalMatch;

    /**
     * The site's own placement of the entry in the locale (PRD 5.8): the released stage of a
     * placement of the entry that is not withdrawn, below a node of the site that has a route in
     * the locale, the canonical one first and then the lowest id; null when the reader can read
     * none. A mount holds no placement, so a placement the site shows through one never counts.
     */
    public function own(SiteId $site, EntryId $entry, Locale $locale): ?OwnPlacement;

    /**
     * The fields of the entry's released row in its type's table, the delivery projection of what
     * the public sees (PRD 4.1, 8.9): the shared variant's row for a type without localization, and
     * the locale's row, which holds the shared fields too, for a type with localization variants
     * (PRD 10.1, 11.6); null when the reader can read no such row.
     */
    public function released(TypeDefinition $type, EntryId $entry, Locale $locale): ?FieldValues;
}
