<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Writes the structure to the core's tables on Postgres as the owner role (PRD 5.8, 5.9), for tests
 * that run placements, routing and access against real Postgres: sites with their locales, nodes
 * below them, mounts and node routes.
 *
 * Node and site commands come with block B2, so in M1 the structure is written only here. The app
 * role writes none of these tables, and their row level security lets the owner write them (see
 * the core's migration of the placement commands), so the fixtures write on the owner connection.
 * A node's path is the labels of its ancestors' ids and its own, each the id's 32 hex digits, as
 * the core's `nodes` table requires; a site's root is a node of kind `site` with the route `/` in
 * each of its locales.
 */
#[Experimental]
final readonly class PostgresStructureFixtures
{
    public const string NODES = 'nodes';

    public const string SITES = 'sites';

    public const string SITE_LOCALES = 'site_locales';

    public const string ROUTES = 'node_routes';

    /** The kinds of node the core's `nodes` table takes (PRD 5.8), but a mount, which mount() makes. */
    public const array KINDS = ['site', 'section', 'page', 'list', 'storage'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private string $ownerConnection = 'pgsql_owner',
    ) {}

    /**
     * A site with the handle, a root node of kind `site` and the route `/` to it in each locale.
     *
     * @param  list<Locale>  $locales
     *
     * @throws InvalidArgumentException for no locale
     */
    public function site(string $handle, array $locales): StructureSite
    {
        if ($locales === []) {
            throw new InvalidArgumentException('A site publishes in at least one locale.');
        }

        $site = new SiteId($this->ids->next());
        $root = $this->write(null, 'site', null);
        $now = $this->now();

        $this->owner()->transaction(function (ConnectionInterface $owner) use ($site, $handle, $root, $locales, $now): void {
            $owner->table(self::SITES)->insert([
                'id' => $site->toString(),
                'handle' => $handle,
                'root_node_id' => $root->id->toString(),
                'version' => 1,
                'created_at' => $now,
            ]);

            $owner->table(self::SITE_LOCALES)->insert(array_map(static fn (Locale $locale): array => [
                'site_id' => $site->toString(),
                'locale' => $locale->value,
                'created_at' => $now,
            ], $locales));
        });

        $result = new StructureSite($site, $handle, $root, $locales);

        foreach ($locales as $locale) {
            $this->route($result, $locale, '/', $root);
        }

        return $result;
    }

    /**
     * A node of the kind below the parent.
     *
     * @throws InvalidArgumentException for a kind the core does not have, or a mount
     */
    public function node(StructureNode $parent, string $kind = 'section'): StructureNode
    {
        if (! in_array($kind, self::KINDS, true) || $kind === 'site') {
            throw new InvalidArgumentException(sprintf('A node below another is a section, a page, a list or a storage folder, got "%s"; mount() makes a mount.', $kind));
        }

        return $this->write($parent, $kind, null);
    }

    /**
     * A mount below the parent that shows the source's placements (PRD 5.8).
     */
    public function mount(StructureNode $parent, StructureNode $source): StructureNode
    {
        return $this->write($parent, 'mount', $source->id);
    }

    /**
     * The route of the node on the site in the locale, `/` or `/`-separated segments.
     */
    public function route(StructureSite $site, Locale $locale, string $route, StructureNode $node): void
    {
        $this->owner()->table(self::ROUTES)->insert([
            'site_id' => $site->id->toString(),
            'locale' => $locale->value,
            'route' => $route,
            'node_id' => $node->id->toString(),
            'created_at' => $this->now(),
        ]);
    }

    private function write(?StructureNode $parent, string $kind, ?NodeId $source): StructureNode
    {
        $id = new NodeId($this->ids->next());
        $label = str_replace('-', '', $id->toString());
        $path = new NodePath($parent instanceof StructureNode ? $parent->path->value.'.'.$label : $label);

        $this->owner()->table(self::NODES)->insert([
            'id' => $id->toString(),
            'parent_id' => $parent?->id->toString(),
            'kind' => $kind,
            'path' => $path->value,
            'mount_source_id' => $source?->toString(),
            'version' => 1,
            'created_at' => $this->now(),
        ]);

        return new StructureNode($id, $path);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function owner(): ConnectionInterface
    {
        return $this->connections->connection($this->ownerConnection);
    }
}
