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

    expect(RegistryFixtures::files($directory.'/bootstrap/cache/cms'))->toBe(['actions.php', 'commands.php', 'hooks.php'])
        ->and($cache->read())->toEqual(CompiledRegistry::empty())
        ->and($cache->location())->toBe($directory.'/bootstrap/cache/cms');
});

it('owns its directory: every file it does not write is removed, whatever its name', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/subscribers.php', "<?php return [];\n");
    file_put_contents($directory.'/notes.txt', 'left by hand');
    file_put_contents($directory.'/.hidden', 'left by hand');
    file_put_contents($directory.'/subscribers.php.0123456789abcdef.tmp', 'from an old build');
    file_put_contents($directory.'/actions.php.0123456789abcdef.tmp.bak', 'not a temporary file of a write');
    file_put_contents($directory."/hooks.php\n", 'a registry name with a line break after it');
    symlink($directory.'/notes.txt', $directory.'/link.php');
    symlink($directory.'/missing', $directory.'/dangling.php');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php']);
});

it('leaves subdirectories and the temporary file of a concurrent write in place', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory.'/nested', 0o775, true);
    file_put_contents($directory.'/nested/subscribers.php', "<?php return [];\n");
    file_put_contents($directory.'/hooks.php.0123456789abcdef.tmp', 'being written by another cms:build');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php', 'hooks.php.0123456789abcdef.tmp', 'nested'])
        ->and(RegistryFixtures::files($directory.'/nested'))->toBe(['subscribers.php']);
});

it('replaces the files before it removes the others, so a failed write keeps what was there', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/subscribers.php', "<?php return [];\n");
    mkdir($directory.'/hooks.php');

    expect(fn () => RegistryFixtures::cache($directory)->write(CompiledRegistry::empty()))
        ->toThrow(RegistryCacheUnwritable::class, $directory.'/hooks.php');

    expect(RegistryFixtures::files($directory))->toBe(['actions.php', 'commands.php', 'hooks.php', 'subscribers.php']);
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
    unlink($directory.'/commands.php');

    expect(fn (): CompiledRegistry => RegistryFixtures::cache($directory)->read())
        ->toThrow(RegistryCacheMissing::class, $directory.'/commands.php');
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
    file_put_contents($directory.'/actions.php', "<?php return 'actions';\n");

    expect(fn (): CompiledRegistry => RegistryFixtures::cache($directory)->read())
        ->toThrow(MalformedRegistryCache::class, 'expected an array with the keys build, entries, format, registry, got string');
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
