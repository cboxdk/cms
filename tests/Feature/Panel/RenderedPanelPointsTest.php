<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Panel;

use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Tests\Support\Panel\RenderedPanelPoints;
use Cbox\Cms\Tests\Support\Registry\InstallationRegistry;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;

/*
 * Every panel point a page of the panel renders exists, and every declared panel point is rendered
 * by a page (PRD 13.4). The points are those of the installation's registry, compiled here from the
 * scan roots and addon manifests of its service providers as cms:build compiles them, not read from
 * a cache that may be stale; the pages are the .ts and .tsx files of js/panel/src, which render a
 * point with <PointHost point="<name>@<version>">. The cases after the first plant each kind of
 * finding in a scratch directory.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function renderedPoint(string $name, int $version = 1): PanelPointEntry
{
    return new PanelPointEntry(
        new PanelPoint($name, $version, PointKind::Slot, 'account.me', '1.0', 'panel.points.planted', Region::Sections),
        'Acme\\Panel\\PlantedV'.$version,
        'acme/panel',
        PointStability::Experimental,
    );
}

/**
 * A scratch directory with the given files, keyed by path below it.
 *
 * @param  array<string, string>  $files
 */
function renderedPages(array $files): string
{
    $root = ScratchDirectory::make();

    foreach ($files as $path => $source) {
        ScratchDirectory::write($root.'/'.$path, $source);
    }

    return $root;
}

it('finds every point the panel\'s pages render declared, and every declared point rendered', function (): void {
    $registry = InstallationRegistry::compile(app());
    $root = dirname(__DIR__, 3);

    expect(RenderedPanelPoints::findings($root.'/js/panel/src', $root, $registry->panel))->toBe([]);
});

it('finds nothing when the pages render exactly the declared points', function (): void {
    $root = renderedPages([
        'src/pages/Me.tsx' => "export default function Me() {\n  return <PointHost point=\"account.me.sections@1\" props={props} />;\n}\n",
        'src/pages/Me/Actions.tsx' => "export const actions = <PointHost\n  props={props}\n  point='account.me.actions@2'\n/>;\n",
    ]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, [renderedPoint('account.me.sections'), renderedPoint('account.me.actions', 2)]))->toBe([]);
});

it('reports a rendered point that no #[PanelPoint] declares, with its file and line', function (): void {
    $root = renderedPages(['src/pages/Me.tsx' => "import { PointHost } from '../host';\n\nexport const me = <PointHost point=\"account.me.sections@2\" />;\n"]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, []))->toBe(['src/pages/Me.tsx:3: renders account.me.sections@2, which no #[PanelPoint] declares']);
});

it('reports a declared point that no page renders, and does not read one in a comment', function (): void {
    $root = renderedPages(['src/pages/Me.tsx' => "// <PointHost point=\"account.me.sections@1\" />\n/* <PointHost point=\"account.me.sections@1\" /> */\nexport const me = null;\n"]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, [renderedPoint('account.me.sections')]))
        ->toBe(['account.me.sections@1 (Acme\\Panel\\PlantedV1): declared, but no page renders it']);
});

it('reports a host whose point is not a literal id, and one whose literal is no id', function (): void {
    $root = renderedPages([
        'src/pages/A.tsx' => "export const a = <PointHost point={id} />;\n",
        'src/pages/B.ts' => "\n\nexport const b = '<PointHost point=\"account.me.sections\" />';\n",
    ]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, []))->toBe([
        'src/pages/A.tsx:1: <PointHost> without a literal point id, such as point="account.me.sections@1"',
        'src/pages/B.ts:3: renders "account.me.sections", which is not a panel point id',
    ]);
});

it('reads the points a page asks for with usePointHost() too, but not the hook s own declaration', function (): void {
    $root = renderedPages([
        'src/pages/Form.tsx' => "export function Form() {\n  const checks = usePointHost('command.form.checks@1');\n  return <PointHost point=\"command.form.submit@1\" render={render} />;\n}\n",
        'src/host/PointHost.tsx' => "export function usePointHost(point: string): PointHandle {\n  return handle(point);\n}\n",
    ]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, [renderedPoint('command.form.checks'), renderedPoint('command.form.submit')]))->toBe([]);
});

it('reports a usePointHost() without a literal point id, and one no #[PanelPoint] declares', function (): void {
    $root = renderedPages(['src/pages/Form.tsx' => "const point = 'command.form.checks@1';\nconst a = usePointHost(point);\nconst b = usePointHost(\"command.form.steps@1\");\n"]);

    expect(RenderedPanelPoints::findings($root.'/src', $root, []))->toBe([
        "src/pages/Form.tsx:2: usePointHost() without a literal point id, such as usePointHost('account.me.sections@1')",
        'src/pages/Form.tsx:3: renders command.form.steps@1, which no #[PanelPoint] declares',
    ]);
});
