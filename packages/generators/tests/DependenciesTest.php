<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Tests\Support\PackageDependencies;

/*
 * Every package the generators' src uses is declared in its composer.json, in require or in
 * suggest. The monorepo installs everything, so an undeclared package would only break after the
 * split. symfony/yaml is only suggested in milestone 0 (PROGRESS.md, Blokeret); this test keeps
 * that gap visible: remove the suggest entry and it fails.
 */

it('suggests symfony/yaml and says why', function (): void {
    $manifest = PackageDependencies::manifest('generators');

    expect($manifest['suggest'])->toHaveKey('symfony/yaml')
        ->and($manifest['suggest']['symfony/yaml'])->toContain('cms:generate')
        ->and($manifest['require'])->not->toHaveKey('symfony/yaml');
});

it('uses symfony/yaml in src, so the suggestion is not dead weight', function (): void {
    expect(PackageDependencies::usedBy('generators'))->toContain('symfony/yaml');
});

it('declares every package its src uses in require or suggest', function (): void {
    expect(PackageDependencies::undeclared('generators'))->toBe([]);
});

it('fails without the suggest entry for symfony/yaml', function (): void {
    $manifest = PackageDependencies::manifest('generators');
    unset($manifest['suggest']['symfony/yaml']);

    expect(PackageDependencies::undeclared('generators', $manifest))->toBe(['symfony/yaml']);
});

it('fails without a required package', function (): void {
    $manifest = PackageDependencies::manifest('generators');
    unset($manifest['require']['illuminate/console']);

    expect(PackageDependencies::undeclared('generators', $manifest))->toBe(['illuminate/console']);
});
