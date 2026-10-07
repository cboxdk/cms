<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Panel;

use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV2;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteToolbarV1;
use Cbox\Cms\Panel\Tests\Points\PanelPointFixtures;
use Cbox\Cms\Tests\Support\Panel\PointBindings;
use Cbox\Cms\Tests\Support\Registry\InstallationRegistry;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPointSchemas;

/*
 * Every panel point of the installation that an addon may contribute to has its props schema bound
 * in PanelPointSchemas, and every binding binds a declared point (GUARDRAILS 2.2, PRD 13.4). The
 * points are those of the installation's registry, compiled from the scan roots and addon manifests
 * of its service providers as cms:build compiles them. The cases after the first plant each finding.
 */

function schemaPoint(string $class, int $version, PointStability $stability): PanelPointEntry
{
    return new PanelPointEntry(
        new PanelPoint('notes.detail.card', $version, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_card', Region::Sections),
        $class,
        'acme/cms-notes',
        $stability,
    );
}

it('finds a props schema bound to every contributable point of the installation, and no other', function (): void {
    $registry = InstallationRegistry::compile(app());

    expect(PointBindings::findings($registry->panel, PanelPointSchemas::all()))->toBe([]);
});

it('finds nothing when every point is bound, as the fixture points are', function (): void {
    $points = [schemaPoint(NoteCardV1::class, 1, PointStability::Experimental), schemaPoint(NoteCardV2::class, 2, PointStability::Experimental)];
    $bindings = array_values(array_filter(PanelPointFixtures::bindings(), static fn (SchemaBinding $binding): bool => str_starts_with($binding->schema, 'notes.detail.card.')));

    expect(PointBindings::findings($points, $bindings))->toBe([]);
});

it('reports a contributable point without a binding, an internal point with one and a binding of no point', function (): void {
    $bindings = PanelPointFixtures::bindings();

    expect(PointBindings::findings([schemaPoint(NoteCardV1::class, 1, PointStability::Internal), schemaPoint('Acme\\Notes\\NoteBadgeV1', 1, PointStability::Stable)], $bindings))->toBe([
        sprintf('notes.detail.card@1 (%s): an #[Internal] point, bound to %s/notes.detail.card.v1.json; it has no schema binding and no TypeScript', NoteCardV1::class, PanelPointFixtures::SCHEMAS),
        'notes.detail.card@1 (Acme\\Notes\\NoteBadgeV1): no props schema is bound to it in PanelPointSchemas::all(); add notes.detail.card.v1.json and its binding, and run composer generate:protocol',
        sprintf('%s/notes.detail.card.v2.json: binds %s, which declares no panel point of the installation', PanelPointFixtures::SCHEMAS, NoteCardV2::class),
        sprintf('%s/notes.list.toolbar.v1.json: binds %s, which declares no panel point of the installation', PanelPointFixtures::SCHEMAS, NoteToolbarV1::class),
    ]);
});
