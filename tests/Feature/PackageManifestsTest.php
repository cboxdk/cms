<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\PackageManifest;

dataset('packages', [
    'contracts' => ['contracts', 'Contracts'],
    'core' => ['core', 'Core'],
    'testkit' => ['testkit', 'Testkit'],
    'generators' => ['generators', 'Generators'],
    'http' => ['http', 'Http'],
    'cli' => ['cli', 'Cli'],
]);

it('declares the working name, the MIT license and the PSR-4 namespace', function (string $package, string $namespace): void {
    $manifest = PackageManifest::of($package);

    expect($manifest->string('name'))->toBe('cboxdk/cms-'.$package)
        ->and($manifest->string('license'))->toBe('MIT')
        ->and($manifest->psr4())->toBe(["Cbox\\Cms\\{$namespace}\\" => 'src/']);
})->with('packages');

it('requires PHP 8.5', function (string $package): void {
    expect(PackageManifest::of($package)->requires())->toHaveKey('php', '^8.5');
})->with('packages');
