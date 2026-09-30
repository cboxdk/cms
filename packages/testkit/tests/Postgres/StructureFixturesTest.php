<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 * The structure fixtures (PRD 5.8, 5.9): a site with its root node, its locales and the route `/`
 * to its root in each, nodes and mounts below it with paths of their ancestors' labels, and routes,
 * written as the owner role, which the core's tables let write the structure until node and site
 * commands come.
 */

function structureFixtures(): PostgresStructureFixtures
{
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));

    return new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock));
}

/**
 * @return list<string>
 */
function structureRows(string $sql): array
{
    return array_values(array_map(static fn (mixed $row): string => is_object($row) && property_exists($row, 'value') && is_string($row->value) ? $row->value : '', DB::connection('pgsql_owner')->select($sql)));
}

it('writes a site with its root, its locales and the route to its root in each', function (): void {
    $site = structureFixtures()->site('harbour_town', [new Locale('da'), new Locale('en')]);
    $root = str_replace('-', '', $site->root->id->toString());

    expect($site->root->path->value)->toBe($root)
        ->and(structureRows("select handle || ' ' || root_node_id::text as value from sites"))->toBe(['harbour_town '.$site->root->id->toString()])
        ->and(structureRows("select kind || ' ' || path::text as value from nodes"))->toBe(['site '.$root])
        ->and(structureRows('select locale as value from site_locales order by locale'))->toBe(['da', 'en'])
        ->and(structureRows("select locale || ' ' || route || ' ' || node_id::text as value from node_routes order by locale"))
        ->toBe(['da / '.$site->root->id->toString(), 'en / '.$site->root->id->toString()]);
});

it('writes nodes and mounts below a parent, with the path of their ancestors, and routes to them', function (): void {
    $fixtures = structureFixtures();
    $site = $fixtures->site('harbour_town', [new Locale('da')]);
    $section = $fixtures->node($site->root);
    $page = $fixtures->node($section, 'page');
    $mount = $fixtures->mount($site->root, $section);
    $fixtures->route($site, new Locale('da'), '/news', $section);

    expect($page->path->value)->toBe($site->root->path->value.'.'.str_replace('-', '', $section->id->toString()).'.'.str_replace('-', '', $page->id->toString()))
        ->and(structureRows("select kind || ' ' || coalesce(mount_source_id::text, '-') as value from nodes where id = '".$mount->id->toString()."'"))->toBe(['mount '.$section->id->toString()])
        ->and(structureRows("select route as value from node_routes where node_id = '".$section->id->toString()."'"))->toBe(['/news']);
});

it('refuses a site without locales and a node of a kind below another that is not a section, page, list or storage', function (): void {
    $fixtures = structureFixtures();

    expect(fn (): StructureSite => $fixtures->site('empty', []))->toThrow(InvalidArgumentException::class, 'at least one locale')
        ->and(fn (): StructureNode => $fixtures->node($fixtures->site('harbour_town', [new Locale('da')])->root, 'site'))->toThrow(InvalidArgumentException::class, 'got "site"');
});
