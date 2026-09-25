<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\PackageManifest;

it('depends on the contracts and never on the core, so addons can use it without the kernel', function (): void {
    $requires = PackageManifest::of('testkit')->requires();

    expect($requires)->toHaveKey('cboxdk/cms-contracts')
        ->and($requires)->not->toHaveKey('cboxdk/cms-core')
        ->and($requires)->not->toHaveKey('cboxdk/cms-http')
        ->and($requires)->not->toHaveKey('cboxdk/cms-cli')
        ->and($requires)->not->toHaveKey('cboxdk/cms-generators');
});

it('is autoloadable from the Cbox\Cms\Testkit namespace', function (): void {
    expect(PackageManifest::of('testkit')->psr4())->toBe(['Cbox\\Cms\\Testkit\\' => 'src/'])
        ->and(is_dir(__DIR__.'/../src'))->toBeTrue();
});
