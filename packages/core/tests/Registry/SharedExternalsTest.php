<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\SharedExternals;

/*
 * The modules an addon's bundle may import (PRD 13.4) are the shared React modules the panel's
 * import map points at its own copy, which js/panel/shared-modules.json lists, and the panel SDK.
 */

it('lists the shared React modules of js/panel/shared-modules.json', function (): void {
    $modules = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/js/panel/shared-modules.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($modules)->toBeArray();
    $shared = is_array($modules) && is_array($modules['shared'] ?? null) ? array_map(strval(...), array_keys($modules['shared'])) : [];
    sort($shared, SORT_STRING);

    expect(SharedExternals::REACT)->toBe($shared);
});

it('allows the shared modules and the SDK with its subpaths, and nothing else', function (string $specifier, bool $allowed): void {
    expect(SharedExternals::allows($specifier))->toBe($allowed);
})->with([
    ['react', true],
    ['react/jsx-runtime', true],
    ['@cboxdk/cms-panel', true],
    ['@cboxdk/cms-panel/extend', true],
    ['@cboxdk/cms-panel/ui/button', true],
    ['@inertiajs/react', false],
    ['@cboxdk/cms-panel-app', false],
    ['@cboxdk/cms-panel/', false],
    ['lodash', false],
    ['https://cdn.example/react.js', false],
]);
