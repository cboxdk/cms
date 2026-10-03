<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Tests\Support\Browser\PanelModules;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Cbox\Cms\Tests\Support\Node;

/*
 * The panel's shared modules (PRD 13.4): the panel's build has an ES module entry for each React
 * module an addon shares with it, and the page's import map hands an addon those entries, so an
 * addon's hooks run on the React the panel itself runs on. React 19 ships as CommonJS; the entries
 * name every export of React's production build. A module an addon may not import, such as
 * @inertiajs/react, is mapped inside the addon's scope to an entry that throws PanelImportRefused,
 * and is mapped for nothing outside it.
 *
 * Each test puts the probe host (PanelProbe) on the real panel page for an address the panel does
 * not have, from the build `composer panel:build` writes, and loads the test addon acme
 * (PanelModules) with import(). The tests are in the group browser-matrix, which gate 8 also runs
 * in Firefox and WebKit.
 */

beforeEach(function (): void {
    PanelModules::serve();
    app()->instance(ImportMap::class, PanelModules::importMap());
});

it('runs the test addon\'s React hooks on the panel\'s own React instance', function (): void {
    PanelProbe::onPage('/cms/no/such/page');

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);
    $probe = $result['probe'];

    $page->assertSee(PanelPage::text('panel.not_found.title'))
        ->assertNoJavaScriptErrors();
    PanelProbe::assertQuiet($result['log']);

    expect($probe['addon'])->toBe('rendered')
        // The panel's React DOM and the probe's shared react-dom/client are one module, so one renderer started.
        ->and($probe['renderers'])->toBe(1)
        // The addon's `react` is the React whose internals the panel's renderer reads its hooks from.
        ->and($probe['rendererUsesAddonReact'])->toBeTrue()
        ->and($probe['sameReact'])->toBeTrue();

    // A hook of the addon holds its state across renders of the panel's React DOM.
    $page->assertSeeIn('#probe-counter', 'count 0')
        ->click('#probe-counter')
        ->click('#probe-counter')
        ->assertSeeIn('#probe-counter', 'count 2');
    PanelProbe::assertQuiet(PanelProbe::result($page)['log']);
})->group('browser-matrix');

it('hands an addon every export of the production build of each shared module', function (): void {
    PanelProbe::onPage('/cms/no/such/page');

    $probe = PanelProbe::result(visit('/cms/no/such/page'))['probe'];
    $node = Node::run([
        'node',
        '--input-type=commonjs',
        '-e',
        'process.env.NODE_ENV = "production"; process.stdout.write(JSON.stringify(Object.fromEntries(JSON.parse(process.argv[1]).map((s) => [s, [...Object.keys(require(s)), "default"].sort()]))))',
        json_encode(array_keys(ViteManifest::SHARED), JSON_THROW_ON_ERROR),
    ]);

    expect($node->isSuccessful())->toBeTrue($node->getErrorOutput())
        ->and($probe['shared'])->toBe(json_decode($node->getOutput(), true, 4, JSON_THROW_ON_ERROR));
})->group('browser-matrix');

it('refuses an addon\'s import of @inertiajs/react with the documented error, and maps it for nothing outside an addon\'s scope', function (): void {
    PanelProbe::onPage('/cms/no/such/page');

    $page = visit('/cms/no/such/page');
    $result = PanelProbe::result($page);
    $probe = $result['probe'];

    expect($probe['refused'])->toBe([
        'name' => 'PanelImportRefused',
        'message' => 'Cbox CMS panel: an addon may not import @inertiajs/react. The panel\'s router, page state and UI primitives are not addon API; an addon shares only the modules of the panel\'s import map (docs/developers/panel.md#shared-modules).',
    ])
        // Outside the addon's scope the bare specifier resolves to nothing at all.
        ->and($probe['outsideScope'])->toStartWith('refused TypeError');

    // The refusal is the addon's failure only: the panel's page renders on, with nothing reported.
    $page->assertSee(PanelPage::text('panel.not_found.title'))
        ->assertNoJavaScriptErrors();
    PanelProbe::assertQuiet($result['log']);
})->group('browser-matrix');
