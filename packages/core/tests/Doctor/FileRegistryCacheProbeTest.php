<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Doctor\Adapter\FileRegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use DateTimeImmutable;

/*
 * The registry.cache check on real files: it looks at the files of the seven registries and at
 * vendor/composer/installed.json, and at nothing else in the cache directory.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * A built cache in a scratch directory, every registry file written at $builtAt, next to a
 * manifest Composer wrote at $manifestAt.
 *
 * @return array{string, string} the cache directory and the manifest
 */
function registryProbeFixture(int $builtAt, int $manifestAt): array
{
    $root = RegistryFixtures::scratch();
    $directory = $root.'/bootstrap/cache/cms';
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    foreach (['actions.php', 'addons.php', 'commands.php', 'hooks.php', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php'] as $file) {
        touch($directory.'/'.$file, $builtAt);
    }

    mkdir($root.'/vendor/composer', 0o775, true);
    file_put_contents($root.'/vendor/composer/installed.json', '{"packages":[]}');
    touch($root.'/vendor/composer/installed.json', $manifestAt);

    return [$directory, $root.'/vendor/composer/installed.json'];
}

it('dates the cache by the oldest of the seven registry files', function (): void {
    [$directory, $manifest] = registryProbeFixture(2_000_000_000, 1_900_000_000);
    touch($directory.'/commands.php', 1_950_000_000);

    $state = new FileRegistryCacheProbe(RegistryFixtures::cache($directory), $manifest)->state();

    expect($state->missingFiles)->toBe([])
        ->and($state->damage)->toBeNull()
        ->and($state->builtAt)->toEqual(new DateTimeImmutable('@1950000000'))
        ->and($state->manifestChangedAt)->toEqual(new DateTimeImmutable('@1900000000'));
});

it('ignores an old file of a registry the cache no longer writes', function (): void {
    [$directory, $manifest] = registryProbeFixture(2_000_000_000, 1_900_000_000);
    file_put_contents($directory.'/slots.php', "<?php return ['entries' => [], 'format' => 1, 'registry' => 'slots'];\n");
    touch($directory.'/slots.php', 1_000_000_000);

    $probe = new FileRegistryCacheProbe(RegistryFixtures::cache($directory), $manifest);
    $result = new RegistryCacheCheck($probe)->run();

    expect($probe->state()->builtAt)->toEqual(new DateTimeImmutable('@2000000000'))
        ->and($result->status)->toBe(CheckStatus::Pass);
});

it('does not ask for the file of slots', function (): void {
    [$directory, $manifest] = registryProbeFixture(2_000_000_000, 1_900_000_000);

    $result = new RegistryCacheCheck(new FileRegistryCacheProbe(RegistryFixtures::cache($directory), $manifest))->run();

    expect(glob($directory.'/*') ?: [])->toHaveCount(8)
        ->and($result->status)->toBe(CheckStatus::Pass);
});

it('reports a missing registry file by name', function (): void {
    [$directory, $manifest] = registryProbeFixture(2_000_000_000, 1_900_000_000);
    unlink($directory.'/hooks.php');

    $state = new FileRegistryCacheProbe(RegistryFixtures::cache($directory), $manifest)->state();

    expect($state->missingFiles)->toBe(['hooks.php'])
        ->and($state->builtAt)->toBeNull();
});

it('finds the cache stale when a registry file is older than the manifest', function (): void {
    [$directory, $manifest] = registryProbeFixture(2_000_000_000, 1_900_000_000);
    touch($directory.'/commands.php', 1_800_000_000);

    $result = new RegistryCacheCheck(new FileRegistryCacheProbe(RegistryFixtures::cache($directory), $manifest))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->code)->toBe(RegistryCacheCheck::CODE_STALE);
});
