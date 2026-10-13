<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteMatch;
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
        $found = array_values(array_map(
            static fn (array $placed): PlacementMatch => $placed[3],
            array_filter($this->placements, static fn (array $placed): bool => $placed[0]->equals($node) && $placed[1]->equals($locale) && $placed[2]->equals($slug)),
        ));
        usort($found, static fn (PlacementMatch $a, PlacementMatch $b): int => [$a->visibility === Visibility::Withdrawn, $a->placement->toString()] <=> [$b->visibility === Visibility::Withdrawn, $b->placement->toString()]);
        $match = $found[0] ?? null;

        if (! $match instanceof PlacementMatch || ! $mount instanceof NodeId || ! in_array($mount->toString().' '.$match->entry->toString(), $this->hidden, true)) {
            return $match;
        }

        return new PlacementMatch($match->placement, $match->entry, $match->visibility, $match->window, $match->canonical, $match->type, $match->lifecycle, $match->release, true);
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
            $route = null;

            foreach ($this->routes as $key => $routed) {
                [$handle, $routedIn, $path] = explode(' ', $key, 3);

                if ($handle === $site && $routedIn === $locale->value && $routed->equals($node)) {
                    $route = $path;
                }
            }

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
    public function released(TypeDefinition $type, EntryId $entry, Locale $locale): ?FieldValues
    {
        $this->reads++;
        $variant = $type->capabilities->localization === Localization::Variants ? VariantKey::of($locale) : VariantKey::shared();

        return $this->released[$this->rowKey($type->id, $entry, $variant)] ?? null;
    }

    private function rowKey(TypeId $type, EntryId $entry, VariantKey $variant): string
    {
        return $type->toString().' '.$entry->toString().' '.$variant->value;
    }
}
