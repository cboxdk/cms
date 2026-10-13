<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\OwnPlacement;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ReleasedRow;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteRedirect;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteRoute;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Override;

/**
 * The reads of path.resolve from memory (GUARDRAILS 9), held to PostgresRouteReader by
 * RouteReaderBehaviour. It holds what one reader may read, as row level security leaves it: the
 * sites with their locales, the nodes with the site whose tree holds each, the routes, the
 * placements, each below a node with a slug in a locale, and the released rows, each of an entry of
 * a type.
 */
final class FakeRouteReader implements RouteReader
{
    /** @var array<string, array{SiteId, list<string>}> by handle: the site and its locales */
    private array $sites = [];

    /** @var array<string, array{NodeKind, string, ?NodeId}> by node: its kind, its site's handle and a mount's source */
    private array $nodes = [];

    /** @var array<string, NodeId> by "<handle> <locale> <route>" */
    private array $routes = [];

    /** @var list<array{NodeId, Locale, Slug, PlacementMatch}> */
    private array $placements = [];

    /** @var array<string, FieldValues> by "<type> <entry>" */
    private array $released = [];

    /** @var list<string> the entries a mount hides, as "<mount> <entry>" */
    private array $hidden = [];

    /** @var array<string, PlacementId> the slug history, by "<node> <locale> <slug>": the placement that had the slug */
    private array $slugHistory = [];

    /** @var array<string, NodeId> the route history, by "<handle> <locale> <route>": the node that had the route */
    private array $routeHistory = [];

    /** How many reads were made. */
    public int $reads = 0;

    /**
     * @param  list<Locale>  $locales
     */
    public function withSite(SiteHandle $handle, SiteId $site, array $locales): self
    {
        $this->sites[$handle->value] = [$site, array_map(static fn (Locale $locale): string => $locale->value, $locales)];

        return $this;
    }

    public function withNode(NodeId $node, NodeKind $kind, SiteHandle $site, ?NodeId $mountSource = null): self
    {
        $this->nodes[$node->toString()] = [$kind, $site->value, $mountSource];

        return $this;
    }

    public function withRoute(SiteHandle $site, Locale $locale, string $route, NodeId $node): self
    {
        $this->routes[$site->value.' '.$locale->value.' '.$route] = $node;

        return $this;
    }

    public function withPlacement(NodeId $node, Locale $locale, Slug $slug, PlacementMatch $placement): self
    {
        $this->placements[] = [$node, $locale, $slug, $placement];

        return $this;
    }

    /**
     * @param  ?Locale  $locale  the locale whose row it is, for a type with localization variants; null for the shared row
     */
    public function withReleased(TypeId $type, EntryId $entry, FieldValues $fields, ?Locale $locale = null): self
    {
        $this->released[$this->rowKey($type, $entry, $locale instanceof Locale ? VariantKey::of($locale) : VariantKey::shared())] = $fields;

        return $this;
    }

    /**
     * The mount's site hides the entry on the mount (PRD 5.8).
     */
    public function withOverride(NodeId $mount, EntryId $entry): self
    {
        $this->hidden[] = $mount->toString().' '.$entry->toString();

        return $this;
    }

    /**
     * The placement had the slug below the node in the locale before it was renamed (PRD 5.9).
     */
    public function withSlugHistory(NodeId $node, Locale $locale, Slug $slug, PlacementId $placement): self
    {
        $this->slugHistory[$node->toString().' '.$locale->value.' '.$slug->value] = $placement;

        return $this;
    }

    /**
     * The node had the route on the site in the locale before it was moved (PRD 5.9).
     */
    public function withRouteHistory(SiteHandle $site, Locale $locale, string $route, NodeId $node): self
    {
        $this->routeHistory[$site->value.' '.$locale->value.' '.$route] = $node;

        return $this;
    }

    #[Override]
    public function route(SiteHandle $site, Locale $locale, RequestPath $path): ?SiteRoute
    {
        $this->reads++;

        if (! isset($this->sites[$site->value])) {
            return null;
        }

        [$id, $locales] = $this->sites[$site->value];
        $match = null;

        foreach ($path->prefixes() as $prefix) {
            $node = $this->routes[$site->value.' '.$locale->value.' '.$prefix] ?? null;

            if ($node instanceof NodeId && isset($this->nodes[$node->toString()])) {
                [$kind, , $source] = $this->nodes[$node->toString()];
                $match = new RouteMatch($prefix, $node, $kind, $source);

                break;
            }
        }

        return new SiteRoute($id, in_array($locale->value, $locales, true), $match);
    }

    #[Override]
    public function placement(NodeId $node, Locale $locale, Slug $slug, ?NodeId $mount = null): ?PlacementMatch
    {
        $this->reads++;
        /** @var list<array{int, PlacementMatch}> each placement found, with its rank: live first, then the slug history, then withdrawn */
        $found = [];
        $renamed = $this->slugHistory[$node->toString().' '.$locale->value.' '.$slug->value] ?? null;

        foreach ($this->placements as [$placedUnder, $placedIn, $placedAs, $placement]) {
            if (! $placedUnder->equals($node) || ! $placedIn->equals($locale)) {
                continue;
            }

            if ($placedAs->equals($slug)) {
                $found[] = [$placement->visibility === Visibility::Withdrawn ? 2 : 0, $placement];
            } elseif ($renamed instanceof PlacementId && $placement->placement->equals($renamed)) {
                $found[] = [1, new PlacementMatch($placement->placement, $placement->entry, $placement->visibility, $placement->window, $placement->canonical, $placement->type, $placement->lifecycle, $placement->release, renamedTo: $placedAs)];
            }
        }

        usort($found, static fn (array $a, array $b): int => [$a[0], $a[1]->placement->toString()] <=> [$b[0], $b[1]->placement->toString()]);
        $match = $found[0][1] ?? null;

        if (! $match instanceof PlacementMatch || ! $mount instanceof NodeId || ! in_array($mount->toString().' '.$match->entry->toString(), $this->hidden, true)) {
            return $match;
        }

        return new PlacementMatch($match->placement, $match->entry, $match->visibility, $match->window, $match->canonical, $match->type, $match->lifecycle, $match->release, true, $match->renamedTo);
    }

    #[Override]
    public function redirectedRoute(SiteId $site, Locale $locale, RequestPath $path): ?RouteRedirect
    {
        $this->reads++;
        $handle = null;

        foreach ($this->sites as $each => [$id]) {
            if ($id->equals($site)) {
                $handle = $each;
            }
        }

        foreach ($path->prefixes() as $prefix) {
            $node = $this->routeHistory[$handle.' '.$locale->value.' '.$prefix] ?? null;

            if ($node instanceof NodeId) {
                [$kind, , $source] = $this->nodes[$node->toString()] ?? [NodeKind::Section, $handle, null];

                return new RouteRedirect($prefix, $node, $kind, $source, $this->routeOf($handle, $locale, $node));
            }
        }

        return null;
    }

    #[Override]
    public function canonical(EntryId $entry, Locale $locale): ?CanonicalMatch
    {
        $this->reads++;

        foreach ($this->placements as [$node, $placedIn, $slug, $placement]) {
            if (! $placement->entry->equals($entry) || ! $placedIn->equals($locale) || ! $placement->canonical) {
                continue;
            }

            $site = $this->nodes[$node->toString()][1] ?? null;
            $route = $this->routeOf($site, $locale, $node);

            return new CanonicalMatch(
                $placement->placement,
                $node,
                $slug,
                $site === null || $route === null ? null : new SiteHandle($site),
                $route,
            );
        }

        return null;
    }

    #[Override]
    public function own(SiteId $site, EntryId $entry, Locale $locale): ?OwnPlacement
    {
        $this->reads++;
        $handle = null;

        foreach ($this->sites as $each => [$id]) {
            if ($id->equals($site)) {
                $handle = $each;
            }
        }

        /** @var list<array{bool, OwnPlacement}> the placements of the entry on the site, each with whether it is canonical */
        $found = [];

        foreach ($this->placements as [$node, $placedIn, $slug, $placement]) {
            if (! $placement->entry->equals($entry) || ! $placedIn->equals($locale) || $placement->visibility === Visibility::Withdrawn) {
                continue;
            }

            $route = $this->routeOf($handle, $locale, $node);

            if ($route !== null) {
                $found[] = [$placement->canonical, new OwnPlacement($placement->placement, $node, $route, $slug, $placement->visibility, $placement->window)];
            }
        }

        usort($found, static fn (array $a, array $b): int => [! $a[0], $a[1]->placement->toString()] <=> [! $b[0], $b[1]->placement->toString()]);

        return $found[0][1] ?? null;
    }

    #[Override]
    public function released(TypeDefinition $type, EntryId $entry, array $locales): array
    {
        $this->reads++;
        $variants = $type->capabilities->localization === Localization::Variants
            ? array_map(VariantKey::of(...), $locales)
            : [VariantKey::shared()];
        $rows = [];

        foreach ($variants as $variant) {
            $fields = $this->released[$this->rowKey($type->id, $entry, $variant)] ?? null;

            if ($fields instanceof FieldValues) {
                $rows[] = new ReleasedRow($variant, $fields);
            }
        }

        return $rows;
    }

    private function rowKey(TypeId $type, EntryId $entry, VariantKey $variant): string
    {
        return $type->toString().' '.$entry->toString().' '.$variant->value;
    }

    /**
     * The route of the node in the site with the handle in the locale, or null when it has none.
     */
    private function routeOf(?string $site, Locale $locale, NodeId $node): ?string
    {
        foreach ($this->routes as $key => $routed) {
            [$handle, $routedIn, $path] = explode(' ', $key, 3);

            if ($handle === $site && $routedIn === $locale->value && $routed->equals($node)) {
                return $path;
            }
        }

        return null;
    }
}
