<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Tests\Support\PackageDependencies;

/*
 * Every package the generators' src uses is declared in its composer.json. The monorepo installs
 * everything, so an undeclared package would only break after the split. The blueprint reader
 * needs symfony/yaml and opis/json-schema at run time, so both are required, not suggested
 * (blueprint decision 5), and it finds the installed blueprint schema through Composer's
 * InstalledVersions, which composer-runtime-api provides.
 */

it('requires symfony/yaml and opis/json-schema, and suggests nothing', function (): void {
    $manifest = PackageDependencies::manifest('generators');
    $raw = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['require'])->toHaveKey('symfony/yaml', '^8.0')
        ->and($manifest['require'])->toHaveKey('opis/json-schema', '^2.6')
        ->and($manifest['require'])->toHaveKey('composer-runtime-api')
        ->and(is_array($raw) && array_key_exists('suggest', $raw))->toBeFalse();
});

it('uses symfony/yaml, opis/json-schema and the Composer runtime in src, so none is dead weight', function (): void {
    expect(PackageDependencies::usedBy('generators'))
        ->toContain('symfony/yaml')
        ->toContain('opis/json-schema')
        ->toContain('composer-runtime-api');
});

it('declares every package its src uses', function (): void {
    expect(PackageDependencies::undeclared('generators'))->toBe([]);
});

it('fails without a required package', function (string $package): void {
    $manifest = PackageDependencies::manifest('generators');
    unset($manifest['require'][$package]);

    expect(PackageDependencies::undeclared('generators', $manifest))->toBe([$package]);
})->with(['illuminate/console', 'symfony/yaml', 'opis/json-schema', 'composer-runtime-api']);
