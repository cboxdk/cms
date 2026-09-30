<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityPrecedence;
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
 *    the source, and what is found there is shown exactly as the source shows it (PRD 5.8).
 * 3. The rest of the path, one slug, in (node, locale, slug) of the placements' released stage.
 * 4. Whether the entry's type has URLs, then the precedence of PRD 6.6 at the Clock's time.
 * 5. For a visible placement, the canonical placement of the entry in the locale and its URL, the
 *    origin of its configured site with its node's route and its slug; a mount's placement is the
 *    source's, so its canonical URL is on the source's site.
 *
 * The answer is ResolvedPath: the entry, without fields, whenever its placement and entry were read,
 * and the PathExplanation. It costs COST, the three statements of the RouteReader at most, whatever
 * the number of routes and placements.
 *
 * @implements QueryAction<ResolvePath, ResolvedPath>
 */
#[Action(handles: ResolvePath::class)]
#[Internal]
final readonly class ResolvePathAction implements QueryAction
{
    /** The cost of a resolution: at most one route, one placement and one canonical placement. */
    public const int COST = 3;

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

        return $this->below($query, $site, $found->match);
    }

    /**
     * Steps 2 to 5, from the node the route reaches.
     */
    private function below(ResolvePath $query, SiteStep $site, RouteMatch $match): ResolvedPath
    {
        $rest = $query->path->rest($match->route);
        $route = new RouteStep($query->path, $match->route, $rest);
        $node = new NodeStep($match->node, $match->kind);
        $mount = $match->kind === NodeKind::Mount && $match->mountSource instanceof NodeId ? new MountStep($match->node, $match->mountSource) : null;
        $under = $mount instanceof MountStep ? $mount->source : $match->node;
        $slug = $this->slug($rest);

        if (! $slug instanceof Slug) {
            return $this->ended(new PathExplanation(ResolveOutcome::NoSlug, $site, $route, $node, $mount));
        }

        $placement = $this->routes->placement($under, $query->locale, $slug);

        if (! $placement instanceof PlacementMatch) {
            return $this->ended(new PathExplanation(ResolveOutcome::NoPlacement, $site, $route, $node, $mount, new PlacementStep($under, $slug)));
        }

        $type = $placement->type instanceof TypeId ? $this->types->find($placement->type) : null;
        $routable = $type instanceof TypeDefinition && $type->capabilities->routable;
        $step = new PlacementStep($under, $slug, $placement->placement, $placement->entry, $placement->type, $placement->canonical, $routable);
        $content = $placement->type instanceof TypeId ? new ReadContent($placement->entry, $under, $placement->type) : null;

        if ($placement->type instanceof TypeId && ! $routable) {
            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::NotRoutable, $site, $route, $node, $mount, $step));
        }

        $visibility = VisibilityPrecedence::decide($placement, $type?->capabilities->stages, $this->clock->now());

        if (! $visibility->decision->visible()) {
            return new ResolvedPath($content, new PathExplanation(ResolveOutcome::NotVisible, $site, $route, $node, $mount, $step, $visibility));
        }

        $canonical = $this->canonical($query, $placement, $mount);

        return new ResolvedPath($content, new PathExplanation(ResolveOutcome::Resolved, $site, $route, $node, $mount, $step, $visibility, $canonical));
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
