<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use PHPUnit\Framework\Assert;

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

it('creates the directory and its parents when they do not exist', function (): void {
    $directory = RegistryFixtures::scratch();
    $cache = RegistryFixtures::cache($directory.'/bootstrap/cache/cms');

    $cache->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory.'/bootstrap/cache/cms'))->toHaveCount(6)
        ->and($cache->read())->toEqual(CompiledRegistry::empty())
        ->and($cache->location())->toBe($directory.'/bootstrap/cache/cms');
});

it('refuses to read a cache that was never built, and says how to build it', function (): void {
    $directory = RegistryFixtures::scratch();

    try {
        RegistryFixtures::cache($directory)->read();
        Assert::fail('A missing cache was read.');
    } catch (RegistryCacheMissing $missing) {
        expect($missing->getMessage())->toBe(sprintf(
            '[registry_cache_missing] The registry cache file %s/actions.php does not exist. Run php artisan cms:build, which composer dump-autoload also runs, and make the deploy run it.',
            $directory,
        ));
    }
});

it('refuses to read a cache with one file missing', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    unlink($directory.'/slots.php');

    expect(fn (): CompiledRegistry => RegistryFixtures::cache($directory)->read())
        ->toThrow(RegistryCacheMissing::class, $directory.'/slots.php');
});

it('refuses a cache file that is not valid PHP', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    file_put_contents($directory.'/hooks.php', "<?php return [;\n");

    expect(fn (): CompiledRegistry => RegistryFixtures::cache($directory)->read())
        ->toThrow(MalformedRegistryCache::class, 'hooks.php is not valid: loading it failed');
});

it('refuses a cache file that returns something else', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    file_put_contents($directory.'/schema.php', "<?php return 'schema';\n");

    expect(fn (): CompiledRegistry => RegistryFixtures::cache($directory)->read())
        ->toThrow(MalformedRegistryCache::class, 'expected an array with the keys entries, format, registry, got string');
});

it('reports a directory it cannot create with the reason', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/bootstrap', 'a file where the directory should be');

    try {
        RegistryFixtures::cache($directory.'/bootstrap/cache/cms')->write(CompiledRegistry::empty());
        Assert::fail('The cache was written below a file.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())
            ->toStartWith('[registry_cache_unwritable] Could not write the registry cache file '.$directory.'/bootstrap/cache/cms: ')
            ->toContain('mkdir()')
            ->toContain('run php artisan cms:build again');
    }
});

it('reports a file it cannot write, and leaves no temporary file', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    chmod($directory, 0o555);

    try {
        RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
        Assert::fail('The cache was written to a read-only directory.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())->toContain($directory.'/actions.php')->toContain('Permission denied');
    } finally {
        chmod($directory, 0o755);
    }

    expect(RegistryFixtures::files($directory))->toBe([]);
})->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root may write to a read-only directory');

it('reports a rename that fails, and leaves no temporary file', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    mkdir($directory.'/hooks.php');

    try {
        RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
        Assert::fail('A directory was replaced by a cache file.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())->toContain($directory.'/hooks.php')->toContain('rename(');
    }

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php']);
});
