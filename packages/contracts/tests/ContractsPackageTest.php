<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\PackageManifest;

it('depends on nothing but PHP, so addons can require it alone', function (): void {
    expect(PackageManifest::of('contracts')->requires())->toBe(['php' => '^8.5']);
});

it('is autoloadable from the Cbox\Cms\Contracts namespace', function (): void {
    expect(PackageManifest::of('contracts')->psr4())->toBe(['Cbox\\Cms\\Contracts\\' => 'src/'])
        ->and(is_dir(dirname(__DIR__).'/src'))->toBeTrue();
});
