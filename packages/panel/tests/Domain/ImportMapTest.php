<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Panel\Boundary\ImportMapJson;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use InvalidArgumentException;

/*
 * The import map of a panel page (PRD 13.4): the panel's shared modules for everyone, the refused
 * modules only inside an addon's scope, and the SHA-384 of every module the browser loads through
 * it, every URL a path on the panel's own origin.
 */

function importMapBuild(): PanelBuild
{
    return new PanelBuild(
        '/srv/panel/dist',
        'assets/app.js',
        [],
        [],
        ['assets/app.js', 'assets/refused-inertia.js', 'assets/shared-react.js', 'assets/style.css'],
        str_repeat('a', 64),
        ['react' => 'assets/shared-react.js'],
        ['@inertiajs/react' => 'assets/refused-inertia.js'],
        [
            'assets/shared-react.js' => 'sha384-'.str_repeat('B', 64),
            'assets/app.js' => 'sha384-'.str_repeat('A', 64),
            'assets/refused-inertia.js' => 'sha384-'.str_repeat('C', 64),
        ],
    );
}

it('maps the shared modules and gives the integrity of every script of the build, by URL, and no scope', function (): void {
    $map = ImportMap::of(importMapBuild(), static fn (string $file): string => '/cms/build/'.$file);

    expect($map->imports)->toBe(['react' => '/cms/build/assets/shared-react.js'])
        ->and($map->refused)->toBe(['@inertiajs/react' => '/cms/build/assets/refused-inertia.js'])
        ->and($map->scopes)->toBe([])
        ->and($map->integrity)->toBe([
            '/cms/build/assets/app.js' => 'sha384-'.str_repeat('A', 64),
            '/cms/build/assets/refused-inertia.js' => 'sha384-'.str_repeat('C', 64),
            '/cms/build/assets/shared-react.js' => 'sha384-'.str_repeat('B', 64),
        ]);
});

it('gives an addon\'s scope every refused module and adds the integrity of its files', function (): void {
    $map = ImportMap::of(importMapBuild(), static fn (string $file): string => '/cms/build/'.$file)
        ->withAddonScope('/cms/addons/zeta/', ['/cms/addons/zeta/z.js' => 'sha384-'.str_repeat('Z', 64)])
        ->withAddonScope('/cms/addons/acme/', ['/cms/addons/acme/a.js' => 'sha384-'.str_repeat('D', 64)]);

    expect($map->scopes)->toBe([
        '/cms/addons/acme/' => ['@inertiajs/react' => '/cms/build/assets/refused-inertia.js'],
        '/cms/addons/zeta/' => ['@inertiajs/react' => '/cms/build/assets/refused-inertia.js'],
    ])
        ->and(array_keys($map->integrity))->toBe([
            '/cms/addons/acme/a.js',
            '/cms/addons/zeta/z.js',
            '/cms/build/assets/app.js',
            '/cms/build/assets/refused-inertia.js',
            '/cms/build/assets/shared-react.js',
        ])
        ->and($map->imports)->toBe(['react' => '/cms/build/assets/shared-react.js']);
});

it('refuses what an import map of the panel may not hold', function (callable $make, string $reason): void {
    expect($make)->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'a URL of another origin' => [fn (): ImportMap => new ImportMap(['react' => 'https://cdn.example/react.js'], []), 'which is not a path on the panel\'s origin'],
    'a protocol-relative URL' => [fn (): ImportMap => new ImportMap(['react' => '//cdn.example/react.js'], []), 'which is not a path on the panel\'s origin'],
    'a URL that could end the script element' => [fn (): ImportMap => new ImportMap(['react' => '/cms/</script>.js'], []), 'which is not a path on the panel\'s origin'],
    'a dev server URL with integrity' => [fn (): ImportMap => new ImportMap([], [], [], ['http://localhost:5174/x.js' => 'sha384-'.str_repeat('A', 64)]), 'which is not a path on the panel\'s origin'],
    'a dev server over https' => [fn (): ImportMap => new ImportMap(['cms-addons/tally' => 'https://localhost:5174/@cms-panel-addon/entry'], []), 'which is not a path on the panel\'s origin'],
    'an addon twice' => [fn (): ImportMap => new ImportMap([], [])->withAddon(new AddonNamespace('tally'), '/a/', '/a/e.js', [])->withAddon(new AddonNamespace('tally'), '/b/', '/b/e.js', []), 'already has the addon tally'],
    'an addon whose entry lies outside its prefix' => [fn (): ImportMap => new ImportMap([], [])->withAddon(new AddonNamespace('tally'), '/a/', '/b/e.js', []), 'lies outside its prefix /a/'],
    'an addon on a dev server twice' => [fn (): ImportMap => new ImportMap([], [])->withDevServer(server())->withDevServer(server()), 'already has the addon tally'],
    'a relative specifier' => [fn (): ImportMap => new ImportMap(['./react' => '/cms/react.js'], []), 'which is not a bare module specifier'],
    'a scope that is no directory' => [fn (): ImportMap => new ImportMap([], ['@inertiajs/react' => '/r.js'], ['/cms/addons/acme' => ['@inertiajs/react' => '/r.js']]), 'is not a directory'],
    'a scope that maps a module to anything but its refused entry' => [fn (): ImportMap => new ImportMap([], ['@inertiajs/react' => '/r.js'], ['/cms/addons/acme/' => ['@inertiajs/react' => '/real-inertia.js']]), 'which is not the entry of a refused module'],
    'an integrity that is no SHA-384' => [fn (): ImportMap => new ImportMap([], [], [], ['/a.js' => 'sha256-'.str_repeat('A', 43).'=']), 'is not a SHA-384'],
    'a second scope for one addon' => [fn (): ImportMap => new ImportMap([], [])->withAddonScope('/a/', [])->withAddonScope('/a/', []), 'already has a scope for /a/'],
    'an addon file outside its prefix' => [fn (): ImportMap => new ImportMap([], [])->withAddonScope('/a/', ['/b/x.js' => 'sha384-'.str_repeat('A', 64)]), 'lies outside its prefix /a/'],
]);

/**
 * The dev server of the addon tally.
 */
function server(): DevServer
{
    return new DevServer(new AddonNamespace('tally'), 'http://localhost:5174');
}

it('maps an addon\'s entry under cms-addons/<namespace>, with its scope and the integrity of its scripts', function (): void {
    $integrity = 'sha384-'.str_repeat('B', 64);
    $map = new ImportMap(['react' => '/cms/build/assets/shared-react.js'], ['@inertiajs/react' => '/cms/build/assets/refused-inertia.js'])
        ->withAddon(new AddonNamespace('tally'), '/cms/addons/tally/0a/', '/cms/addons/tally/0a/assets/addon.js', ['/cms/addons/tally/0a/assets/addon.js' => $integrity]);

    expect($map->imports)->toBe(['cms-addons/tally' => '/cms/addons/tally/0a/assets/addon.js', 'react' => '/cms/build/assets/shared-react.js'])
        ->and($map->scopes)->toBe(['/cms/addons/tally/0a/' => ['@inertiajs/react' => '/cms/build/assets/refused-inertia.js']])
        ->and($map->integrity)->toBe(['/cms/addons/tally/0a/assets/addon.js' => $integrity])
        ->and(ImportMap::addonSpecifier(new AddonNamespace('tally')))->toBe('cms-addons/tally')
        ->and((string) file_get_contents(Codebase::root().'/js/panel/src/host/PanelRuntime.tsx'))->toContain('return `'.ImportMap::ADDON_SPECIFIER.'${addon}`;');
});

it('maps an addon on a dev server to the server\'s entry, and the shared modules as the server names them, with no integrity', function (): void {
    $map = new ImportMap(['react' => '/cms/build/assets/shared-react.js', '@cboxdk/cms-panel/extend' => '/cms/build/assets/shared-extend.js'], ['@inertiajs/react' => '/cms/build/assets/refused-inertia.js'], [], ['/cms/build/assets/shared-react.js' => 'sha384-'.str_repeat('A', 64)])
        ->withDevServer(server());

    expect($map->imports)->toBe([
        '@cboxdk/cms-panel/extend' => '/cms/build/assets/shared-extend.js',
        'cms-addons/tally' => 'http://localhost:5174/@cms-panel-addon/entry',
        'http://localhost:5174/@id/@cboxdk/cms-panel/extend' => '/cms/build/assets/shared-extend.js',
        'http://localhost:5174/@id/react' => '/cms/build/assets/shared-react.js',
        'react' => '/cms/build/assets/shared-react.js',
    ])
        ->and($map->scopes)->toBe([])
        ->and(array_keys($map->integrity))->toBe(['/cms/build/assets/shared-react.js'])
        ->and(ImportMapJson::encode($map))->toContain('"cms-addons/tally":"http://localhost:5174/@cms-panel-addon/entry"');
});
