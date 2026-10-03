<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Boundary;

use Cbox\Cms\Panel\Boundary\ImportMapJson;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;

/*
 * The text of a panel page's <script type="importmap">: imports, scopes and integrity, each an
 * object even when empty, as browsers require, and nothing that could end the script element.
 */

it('writes imports, scopes and integrity as objects, empty ones included, and leaves the refused modules to the scopes', function (): void {
    expect(ImportMapJson::encode(new ImportMap(['react' => '/cms/build/react.js'], ['@inertiajs/react' => '/cms/build/refused.js'])))
        ->toBe('{"imports":{"react":"/cms/build/react.js"},"scopes":{},"integrity":{}}');
});

it('writes a scope per addon and the integrity of each module', function (): void {
    $hash = 'sha384-'.str_repeat('A', 63).'+';
    $map = new ImportMap([], ['@inertiajs/react' => '/cms/build/refused.js'])->withAddonScope('/cms/addons/acme/', ['/cms/addons/acme/a.js' => $hash]);

    expect(json_decode(ImportMapJson::encode($map), true, 8, JSON_THROW_ON_ERROR))->toBe([
        'imports' => [],
        'scopes' => ['/cms/addons/acme/' => ['@inertiajs/react' => '/cms/build/refused.js']],
        'integrity' => ['/cms/addons/acme/a.js' => $hash],
    ]);
});

it('escapes what could end the script element or start markup', function (): void {
    $json = ImportMapJson::encode(new ImportMap(['react' => '/cms/build/a&b.js'], []));

    expect($json)->not->toContain('&')
        ->and(json_decode($json, true, 8, JSON_THROW_ON_ERROR))->toBe(['imports' => ['react' => '/cms/build/a&b.js'], 'scopes' => [], 'integrity' => []]);
});
