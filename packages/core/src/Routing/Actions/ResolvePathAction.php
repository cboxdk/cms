<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackResolution;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\OwnPlacement;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\RedirectStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteRedirect;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\LocaleFallback;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RedirectReason;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityPrecedence;
use DateTimeImmutable;
use Override;

/**
 * path.resolve (PRD 5.8, 5.9, 6.6, GUARDRAILS 5): resolves a host, a locale and a path to the
 * placement it shows, and explains every step it took, in the read's transaction under the read's
 * actor context:
 *
 * 1. The host to a site, from the configured sites (SiteHosts), never from anything else the
 *    request says; then the site with that handle and whether it publishes in the locale.
 * 2. The longest route of the site in the locale that is a prefix of the path (`node_routes`),
 *    and the node it reaches. A mount shows its source's placements: the slug is looked up below
 *    the source, and what is found there is shown exactly as the source shows it (PRD 5.8), unless
 *    the mount's site hides the entry there, which the same lookup reads.
 * 3. The rest of the path, one slug, in (node, locale, slug) of the placements' released stage,
 *    and, when no placement has the slug now, the slug history (PRD 5.9): a placement that had the
 *    slug before it was renamed is found with its current slug, and a visible one ends the
 *    resolution in a redirect to its current URL below the same route; one that is not visible
 *    answers as it would at its current URL, 410 for a withdrawn one. When the live lookup and the
 *    slug history answer nothing, the prefix redirects: a route a node had before it was moved that
 *    is a longer prefix of the path than the route matched redirects to the same rest of the path
 *    below the node's current route, with a renamed slug redirected to its current one as well, so
 *    every redirect is one hop, also after several renames.
 * 4. Whether the entry's type has URLs. Through a mount, whether the site has a placement of its
 *    own of the entry: one that shows at the Clock's time below a node with a route of the site,
 *    while the entry itself can be shown. It suppresses the mounted one, and the resolution ends in
 *    a redirect to its URL, the site's origin with the node's route and its slug (PRD 5.8), in one
 *    hop: the target is a page that shows now, never another redirect (PRD 5.9).
 * 5. The precedence of PRD 6.6 at the Clock's time.
 * 6. For a visible placement, the fields of the entry's released row in its type's table, and the
 *    canonical placement of the entry in the locale and its URL, the origin of its configured site
 *    with its node's route and its slug; a mount's placement is the source's, so its canonical URL
 *    is on the source's site. For a type with localization variants, the fields fall back along
 *    the site's fallback chain (PRD 10.3, LocaleFallback): the first locale from the requested one
 *    on that has a released row, with the fields marked `fallback: field` it leaves empty taken
 *    from the locales after it, explained by the fallback step; with no released row in any of
 *    them the placement is not shown (rung 8, NoReleasedRevision).
 *
 * The answer is ResolvedPath: the entry whenever its placement and entry were read, with the fields
 * of its released row when the placement is visible and without fields otherwise, and the
 * PathExplanation. It costs COST, the five statements of the RouteReader at most, whatever the
 * number of routes, placements and history rows.
 *
 * @implements QueryAction<ResolvePath, ResolvedPath>
 */
#[Action(handles: ResolvePath::class)]
#[Internal]
final readonly class ResolvePathAction implements QueryAction
{
    /**
     * The cost of a resolution: at most one route, one placement, a site's own placement through a
     * mount, the released row and one canonical placement; a prefix redirect reads the route history
     * and one placement below the node instead of the last three.
     */
    public const int COST = 5;

    public function __construct(
        private RouteReader $routes,
        private SiteHosts $sites,
        private TypeCatalog $types,
        private Clock $clock,
    ) {}

    /**
     * @param  ResolvePath  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @param  ResolvePath  $query
     */
    #[Override]
    public function handle(Query $query): ResolvedPath
    {
        $configured = $this->sites->serving($query->host);

        if (! $configured instanceof ConfiguredSite) {
            return $this->ended(new PathExplanation(ResolveOutcome::UnknownHost, new SiteStep($query->host, $query->locale, null, null, false)));
        }

        $found = $this->routes->route($configured->handle, $query->locale, $query->path);
        $site = new SiteStep($query->host, $query->locale, $configured->handle, $found?->site, $found instanceof SiteRoute && $found->localePublished);

        if (! $found instanceof SiteRoute) {
            return $this->ended(new PathExplanation(ResolveOutcome::UnknownSite, $site));
        }

        if (! $found->localePublished) {
            return $this->ended(new PathExplanation(ResolveOutcome::LocaleNotPublished, $site));
        }

        if (! $found->match instanceof RouteMatch) {
            return $this->ended(new PathExplanation(ResolveOutcome::NoRoute, $site, new RouteStep($query->path, null, null)));
        }

        return $this->below($query, $configured, $site, $found->site, $found->match);
    }

    /**
     * Steps 2 to 6, from the node the route reaches.
     */
    private function below(ResolvePath $query, ConfiguredSite $configured, SiteStep $site, SiteId $siteId, RouteMatch $match): ResolvedPath
    {
        $rest = $query->path->rest($match->route);
        $route = new RouteStep($query->path, $match->route, $rest);
        $node = new NodeStep($match->node, $match->kind);
        $mount = $match->kind === NodeKind::Mount && $match->mountSource instanceof NodeId ? new MountStep($match->node, $match->mountSource) : null;
        $under = $mount instanceof MountStep ? $mount->source : $match->node;
        $slug = $this->slug($rest);

        if (! $slug instanceof Slug) {
            return $this->movedRoute($query, $configured, $site, $siteId, $match->route)
                ?? $this->ended(new PathExplanation(ResolveOutcome::NoSlug, $site, $route, $node, $mount));
        }

        $placement = $this->routes->placement($under, $query->locale, $slug, $mount?->mount);

        if (! $placement instanceof PlacementMatch) {
            return $this->movedRoute($query, $configured, $site, $siteId, $match->route)
                ?? $this->ended(new PathExplanation(ResolveOutcome::NoPlacement, $site, $route, $node, $mount, new PlacementStep($under, $slug)));
        }

        $type = $placement->type instanceof TypeId ? $this->types->find($placement->type) : null;
        $routable = $type instanceof TypeDefinition && $type->capabilities->routable;
        $step = new PlacementStep($under, $slug, $placement->placement, $placement->entry, $placement->type, $placement->canonical, $routable);
        $content = $placement->type instanceof TypeId ? new ReadContent($placement->entry, $under, $placement->type) : null;

        if ($placement->type instanceof TypeId && ! $routable) {
            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::NotRoutable, $site, $route, $node, $mount, $step));
        }

        $now = $this->clock->now();
        $redirect = $mount instanceof MountStep && $type instanceof TypeDefinition ? $this->ownPlacement($query, $configured, $siteId, $placement, $type, $now) : null;

        if ($redirect instanceof RedirectStep) {
            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::Redirect, $site, $route, $node, $mount, $step, redirect: $redirect));
        }

        $visibility = VisibilityPrecedence::decide($placement, $type?->capabilities->stages, $now);

        if (! $visibility->decision->visible()) {
            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::NotVisible, $site, $route, $node, $mount, $step, $visibility));
        }

        if ($placement->renamedTo instanceof Slug) {
            $redirect = new RedirectStep($configured->origin->url($this->pathBelow($match->route, $placement->renamedTo->value)), $placement->placement, RedirectReason::SlugChanged, $visibility->validUntil);

            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::Redirect, $site, $route, $node, $mount, $step, redirect: $redirect));
        }

        $fallback = null;

        if ($type instanceof TypeDefinition && $content instanceof ReadContent) {
            $read = $this->fields($type, $configured, $query, $placement);
            $fallback = $read instanceof FallbackResolution ? $read->step : null;
            $fields = $read instanceof FallbackResolution ? $read->fields : $read;

            if (! $fields instanceof FieldValues) {
                $visibility = VisibilityPrecedence::noReleasedVariant($placement, $now);

                return new ResolvedPath($content, new PathExplanation(ResolveOutcome::NotVisible, $site, $route, $node, $mount, $step, $visibility, fallback: $fallback));
            }

            $content = $content->withFields($fields);
        }

        $canonical = $this->canonical($query, $placement, $mount);

        return new ResolvedPath($content, new PathExplanation(ResolveOutcome::Resolved, $site, $route, $node, $mount, $step, $visibility, $canonical, fallback: $fallback));
    }

    /**
     * Step 6's fields (PRD 10.3, 11.6): the shared variant's released row for a type without
     * localization, with no fallback; for a type with localization variants, what the fallback
     * gives over the released rows of the locale and those after it in the site's fallback chain,
     * read at once.
     */
    private function fields(TypeDefinition $type, ConfiguredSite $configured, ResolvePath $query, PlacementMatch $placement): FieldValues|FallbackResolution
    {
        if ($type->capabilities->localization !== Localization::Variants) {
            return $this->routes->released($type, $placement->entry, [$query->locale])[0]->fields ?? new FieldValues;
        }

        $tried = $configured->fallback->from($query->locale);

        return LocaleFallback::resolve($type, $tried, $this->routes->released($type, $placement->entry, $tried));
    }

    /**
     * Step 4 through a mount (PRD 5.8): the redirect to the site's own placement of the entry, when
     * the site has one that shows at the time and the entry itself can be shown; null otherwise,
     * and the mount shows the source's placement as it is.
     */
    private function ownPlacement(ResolvePath $query, ConfiguredSite $configured, SiteId $site, PlacementMatch $placement, TypeDefinition $type, DateTimeImmutable $at): ?RedirectStep
    {
        if (! VisibilityPrecedence::entryShowable($placement, $type->capabilities->stages)) {
            return null;
        }

        $own = $this->routes->own($site, $placement->entry, $query->locale);

        if (! $own instanceof OwnPlacement || ! $own->visibility->visibleAt($own->window, $at)) {
            return null;
        }

        return new RedirectStep($configured->origin->url($own->path()), $own->placement, RedirectReason::OwnPlacement, Visibility::nextTransition($own->window, $at));
    }

    /**
     * The prefix redirect (PRD 5.9), consulted once the live lookup answered nothing: when a route a
     * node had before it was moved is a longer prefix of the path than the route matched, and the
     * node has a route on the site in the locale now, the resolution ends in a redirect to the same
     * rest of the path below the current route, in one hop. The rest is looked up as a slug below
     * the node, the mount's source for a mount, so a slug that was renamed as well redirects to its
     * current one, and the answer carries the placement found, whatever its visibility: the target
     * is the current URL, which answers for itself. Null when no such route is a prefix of the path.
     */
    private function movedRoute(ResolvePath $query, ConfiguredSite $configured, SiteStep $site, SiteId $siteId, string $matched): ?ResolvedPath
    {
        $moved = $this->routes->redirectedRoute($siteId, $query->locale, $query->path);

        if (! $moved instanceof RouteRedirect || strlen($moved->from) <= strlen($matched) || $moved->current === null) {
            return null;
        }

        $rest = $query->path->rest($moved->from) ?? '';
        $route = new RouteStep($query->path, $moved->from, $rest);
        $node = new NodeStep($moved->node, $moved->kind);
        $mount = $moved->kind === NodeKind::Mount && $moved->mountSource instanceof NodeId ? new MountStep($moved->node, $moved->mountSource) : null;
        $under = $mount instanceof MountStep ? $mount->source : $moved->node;
        $slug = $this->slug($rest);
        $placement = $slug instanceof Slug ? $this->routes->placement($under, $query->locale, $slug, $mount?->mount) : null;
        $step = null;
        $content = null;
        $target = $rest;

        if ($slug instanceof Slug && $placement instanceof PlacementMatch) {
            $type = $placement->type instanceof TypeId ? $this->types->find($placement->type) : null;
            $step = new PlacementStep($under, $slug, $placement->placement, $placement->entry, $placement->type, $placement->canonical, $type instanceof TypeDefinition && $type->capabilities->routable);
            $content = $placement->type instanceof TypeId ? new ReadContent($placement->entry, $under, $placement->type) : null;
            $target = ($placement->renamedTo ?? $slug)->value;
        } elseif ($slug instanceof Slug) {
            $step = new PlacementStep($under, $slug);
        }

        $redirect = new RedirectStep($configured->origin->url($this->pathBelow($moved->current, $target)), $placement?->placement, RedirectReason::RouteChanged, null);

        return new ResolvedPath($content, new PathExplanation(ResolveOutcome::Redirect, $site, $route, $node, $mount, $step, redirect: $redirect));
    }

    /**
     * The path of the rest below the route: the route alone for nothing, else the route and the
     * rest with one slash between them.
     */
    private function pathBelow(string $route, string $rest): string
    {
        return match (true) {
            $rest === '' => $route,
            $route === '/' => '/'.$rest,
            default => $route.'/'.$rest,
        };
    }

    private function canonical(ResolvePath $query, PlacementMatch $placement, ?MountStep $mount): CanonicalStep
    {
        $canonical = $this->routes->canonical($placement->entry, $query->locale);

        if (! $canonical instanceof CanonicalMatch) {
            return new CanonicalStep(null, null, false);
        }

        $path = $canonical->path();
        $origin = $canonical->site instanceof SiteHandle ? $this->sites->named($canonical->site)?->origin : null;

        return new CanonicalStep(
            $canonical->placement,
            $path === null || ! $origin instanceof SiteOrigin ? null : $origin->url($path),
            ! $mount instanceof MountStep && $canonical->placement->equals($placement->placement),
        );
    }

    /**
     * The rest of the path as one slug, or null when it is nothing or more than one segment.
     */
    private function slug(?string $rest): ?Slug
    {
        if ($rest === null || $rest === '') {
            return null;
        }

        try {
            return new Slug($rest);
        } catch (InvalidContentValue) {
            return null;
        }
    }

    private function ended(PathExplanation $explanation): ResolvedPath
    {
        return new ResolvedPath(null, $explanation);
    }
}
