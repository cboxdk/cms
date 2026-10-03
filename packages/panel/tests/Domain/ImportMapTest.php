<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
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
    'a relative specifier' => [fn (): ImportMap => new ImportMap(['./react' => '/cms/react.js'], []), 'which is not a bare module specifier'],
    'a scope that is no directory' => [fn (): ImportMap => new ImportMap([], ['@inertiajs/react' => '/r.js'], ['/cms/addons/acme' => ['@inertiajs/react' => '/r.js']]), 'is not a directory'],
    'a scope that maps a module to anything but its refused entry' => [fn (): ImportMap => new ImportMap([], ['@inertiajs/react' => '/r.js'], ['/cms/addons/acme/' => ['@inertiajs/react' => '/real-inertia.js']]), 'which is not the entry of a refused module'],
    'an integrity that is no SHA-384' => [fn (): ImportMap => new ImportMap([], [], [], ['/a.js' => 'sha256-'.str_repeat('A', 43).'=']), 'is not a SHA-384'],
    'a second scope for one addon' => [fn (): ImportMap => new ImportMap([], [])->withAddonScope('/a/', [])->withAddonScope('/a/', []), 'already has a scope for /a/'],
    'an addon file outside its prefix' => [fn (): ImportMap => new ImportMap([], [])->withAddonScope('/a/', ['/b/x.js' => 'sha384-'.str_repeat('A', 64)]), 'lies outside its prefix /a/'],
]);
