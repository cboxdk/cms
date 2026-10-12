<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\MountedEntry;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredMountOverride;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use DateTimeImmutable;
use Override;

/**
 * The NodeReader of the node action tests: the nodes, routes and placements a test adds, read as
 * PostgresNodeReader reads them. NodeReaderBehaviour holds it to that reader.
 */
final class FakeNodeReader implements NodeReader
{
    /** @var array<string, StoredNode> by the node's id */
    public array $nodes = [];

    /** @var array<string, string> the node's id by "<site>|<locale>|<route>" */
    public array $routes = [];

    /** @var list<array{PlacementId, NodePath, ?DateTimeImmutable, bool, ?EntryId}> the placement, the path of its node, the end of its window, whether its state shows it, and its entry */
    public array $placements = [];

    /** @var array<string, StoredMountOverride> by "<mount>|<entry>" */
    public array $overrides = [];

    /** @var array<string, EntryId> the entries that exist, by id */
    public array $entries = [];

    /** @var list<string> each read, as "node <uuid>", "ancestors <uuid>", "routed <uuid>", "holder <site>|<locale>|<route>", "route <site>|<locale>|<node>", "placement <node>", "override <mount>|<entry>" or "mounted <node>|<entry>" */
    public array $reads = [];

    /**
     * A node of the kind below the parent, at the version, with its path below the parent's.
     */
    public function withNode(
        NodeId $node,
        ?StoredNode $parent = null,
        NodeKind $kind = NodeKind::Section,
        NodeLifecycle $lifecycle = NodeLifecycle::Active,
        int $version = 1,
        bool $reachable = true,
        ?NodeId $mountSource = null,
    ): StoredNode {
        $label = str_replace('-', '', $node->toString());
        $path = new NodePath($parent instanceof StoredNode ? $parent->path->value.'.'.$label : $label);
        $stored = new StoredNode($node, $parent?->id, $kind, $path, $lifecycle, new AggregateVersion($version), $reachable, $mountSource);
        $this->nodes[$node->toString()] = $stored;

        return $stored;
    }

    /**
     * Forgets every placement, for a test that archives a node.
     */
    public function withoutPlacements(): self
    {
        $this->placements = [];

        return $this;
    }

    public function withRoute(SiteId $site, Locale $locale, RequestPath $route, NodeId $node): void
    {
        $this->routes[$this->routeKey($site, $locale, $route->value)] = $node->toString();
    }

    /**
     * A placement below the node, whose window ends at $until, null for a window without an end,
     * and whose stored state shows it unless $shown is false.
     */
    public function withPlacement(PlacementId $placement, StoredNode $node, ?DateTimeImmutable $until = null, bool $shown = true, ?EntryId $entry = null): void
    {
        $this->placements[] = [$placement, $node->path, $until, $shown, $entry];

        if ($entry instanceof EntryId) {
            $this->withEntry($entry);
        }
    }

    /**
     * An entry that exists, which a mount's override may name.
     */
    public function withEntry(EntryId $entry): void
    {
        $this->entries[$entry->toString()] = $entry;
    }

    /**
     * The mount hides the entry, set at the version of the mount node given.
     */
    public function withOverride(NodeId $mount, EntryId $entry, int $version = 1): void
    {
        $this->overrides[$mount->toString().'|'.$entry->toString()] = new StoredMountOverride($mount, $entry, true, new AggregateVersion($version));
    }

    #[Override]
    public function node(NodeId $node): ?StoredNode
    {
        $this->reads[] = 'node '.$node->toString();

        return $this->nodes[$node->toString()] ?? null;
    }

    #[Override]
    public function ancestors(NodeId $node): array
    {
        $this->reads[] = 'ancestors '.$node->toString();
        $path = ($this->nodes[$node->toString()] ?? null)?->path;

        if (! $path instanceof NodePath) {
            return [];
        }

        $ancestors = array_values(array_filter($this->nodes, static fn (StoredNode $other): bool => $other->path->isAbove($path)));
        usort($ancestors, static fn (StoredNode $a, StoredNode $b): int => substr_count($a->path->value, '.') <=> substr_count($b->path->value, '.'));

        return $ancestors;
    }

    #[Override]
    public function routedBelow(NodeId $node): ?NodeId
    {
        $this->reads[] = 'routed '.$node->toString();
        $path = ($this->nodes[$node->toString()] ?? null)?->path;

        if (! $path instanceof NodePath) {
            return null;
        }

        $routed = array_values(array_filter(
            $this->nodes,
            fn (StoredNode $other): bool => $path->contains($other->path) && in_array($other->id->toString(), $this->routes, true),
        ));
        usort($routed, static fn (StoredNode $a, StoredNode $b): int => [substr_count($a->path->value, '.'), $a->id->toString()] <=> [substr_count($b->path->value, '.'), $b->id->toString()]);

        return $routed[0]->id ?? null;
    }

    #[Override]
    public function routeHolder(SiteId $site, Locale $locale, RequestPath $route): ?NodeId
    {
        $key = $this->routeKey($site, $locale, $route->value);
        $this->reads[] = 'holder '.$key;
        $holder = $this->routes[$key] ?? null;

        return $holder === null ? null : NodeId::fromString($holder);
    }

    #[Override]
    public function routeOf(SiteId $site, Locale $locale, NodeId $node): ?RequestPath
    {
        $this->reads[] = 'route '.$site->toString().'|'.$locale->value.'|'.$node->toString();

        foreach ($this->routes as $key => $holder) {
            [$held, $tag, $route] = explode('|', $key, 3);

            if ($held === $site->toString() && $tag === $locale->value && $holder === $node->toString()) {
                return new RequestPath($route);
            }
        }

        return null;
    }

    #[Override]
    public function visiblePlacement(NodeId $node, DateTimeImmutable $at): ?PlacementId
    {
        $this->reads[] = 'placement '.$node->toString();
        $path = ($this->nodes[$node->toString()] ?? null)?->path;

        if (! $path instanceof NodePath) {
            return null;
        }

        $found = [];

        foreach ($this->placements as [$placement, $below, $until, $shown]) {
            if ($shown && $path->contains($below) && (! $until instanceof DateTimeImmutable || $until > $at)) {
                $found[] = $placement;
            }
        }

        usort($found, static fn (PlacementId $a, PlacementId $b): int => strcmp($a->toString(), $b->toString()));

        return $found[0] ?? null;
    }

    #[Override]
    public function mountOverride(NodeId $mount, EntryId $entry): ?StoredMountOverride
    {
        $key = $mount->toString().'|'.$entry->toString();
        $this->reads[] = 'override '.$key;

        return $this->overrides[$key] ?? null;
    }

    #[Override]
    public function mountedEntry(NodeId $node, EntryId $entry, DateTimeImmutable $at): ?MountedEntry
    {
        $this->reads[] = 'mounted '.$node->toString().'|'.$entry->toString();

        if (! isset($this->entries[$entry->toString()])) {
            return null;
        }

        $path = ($this->nodes[$node->toString()] ?? null)?->path;
        $found = [];

        foreach ($this->placements as [$placement, $below, $until, $shown, $placed]) {
            if ($placed instanceof EntryId && $placed->equals($entry) && $shown && $path instanceof NodePath && $path->contains($below) && (! $until instanceof DateTimeImmutable || $until > $at)) {
                $found[] = $placement;
            }
        }

        usort($found, static fn (PlacementId $a, PlacementId $b): int => strcmp($a->toString(), $b->toString()));

        return new MountedEntry($entry, $found[0] ?? null);
    }

    private function routeKey(SiteId $site, Locale $locale, string $route): string
    {
        return $site->toString().'|'.$locale->value.'|'.$route;
    }
}
