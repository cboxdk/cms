<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
 * Two cms:build runs that both finish, such as a deploy's next to the one composer's
 * post-autoload-dump runs, write the cache one after the other: a write holds an exclusive flock()
 * on FileRegistryCache::LOCK_FILE while it renames its files and removes the others, so their
 * renames never interleave and the cache never holds the files of two builds for good. The test
 * plays one writer by hand, holding the lock as write() does, and a child PHP process runs the
 * other write.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * Takes the lock of a cache directory as a write does, in a file description of its own, so a
 * write in this process waits for it like one in another process.
 *
 * @return resource
 */
function registryLockHeld(string $directory)
{
    $lock = fopen($directory.'/'.FileRegistryCache::LOCK_FILE, 'c');
    Assert::assertIsResource($lock);
    Assert::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'The lock of the registry cache is held by a write.');

    return $lock;
}

/**
 * @param  resource  $lock
 */
function registryLockRelease($lock): void
{
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * Whether no write holds the lock of a cache directory now.
 */
function registryLockFree(string $directory): bool
{
    $lock = fopen($directory.'/'.FileRegistryCache::LOCK_FILE, 'c');
    Assert::assertIsResource($lock);
    $free = flock($lock, LOCK_EX | LOCK_NB);
    registryLockRelease($lock);

    return $free;
}

/**
 * The registry of the Valid fixture, found through a root of the given package; two packages give
 * two builds.
 */
function registryOfPackage(string $package): CompiledRegistry
{
    return new RegistryCompiler()->compile(RegistryFixtures::validDiscovery($package));
}

it('lets one write run at a time, so a build that finishes while another renames its files leaves one whole build', function (): void {
    $directory = RegistryFixtures::scratch();
    $prepared = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    RegistryFixtures::cache($prepared)->write(registryOfPackage('acme/first-build'));
    $second = registryOfPackage('acme/second-build');

    // The first build has taken the lock and renamed its first file into place.
    $lock = registryLockHeld($directory);
    [$first, $rest] = [RegistryName::cases()[0], array_slice(RegistryName::cases(), 1)];
    Assert::assertTrue(rename($prepared.'/'.$first->fileName(), $directory.'/'.$first->fileName()));

    // The second build writes its whole registry in another process meanwhile.
    $root = dirname(__DIR__, 4);
    $child = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; (new %s(%s, new %s))->write((new %s)->compile(%s::validDiscovery(%s)));',
        var_export($root.'/vendor/autoload.php', true),
        '\\'.FileRegistryCache::class,
        var_export($directory, true),
        '\\'.RegistryCacheCodec::class,
        '\\'.RegistryCompiler::class,
        '\\'.RegistryFixtures::class,
        var_export('acme/second-build', true),
    )], $root);
    $child->start();

    try {
        // Without the lock the child is done well within this; with it, it waits.
        $deadline = hrtime(true) + 1_000_000_000;

        while ($child->isRunning() && hrtime(true) < $deadline) {
            usleep(10_000);
        }

        $waited = $child->isRunning();

        // The first build renames the rest of its files and releases the lock.
        foreach ($rest as $name) {
            Assert::assertTrue(rename($prepared.'/'.$name->fileName(), $directory.'/'.$name->fileName()));
        }
    } finally {
        registryLockRelease($lock);
    }

    $child->wait();

    expect($child->getExitCode())->toBe(0, $child->getErrorOutput().$child->getOutput())
        ->and(RegistryFixtures::cache($directory)->read())->toEqual($second)
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
        ->and($waited)->toBeTrue();
});

it('writes nothing while another write holds the lock, and says so when the wait is over', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(registryOfPackage('acme/first-build'));
    $before = RegistryFixtures::hashes($directory);
    $lock = registryLockHeld($directory);
    $started = hrtime(true);

    try {
        new FileRegistryCache($directory, new RegistryCacheCodec, 50)->write(CompiledRegistry::empty());
        Assert::fail('The cache was written while another write held its lock.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())->toBe(sprintf(
            '[registry_cache_unwritable] Could not write the registry cache: another cms:build held the lock %s/.lock for more than 50 ms and is still writing the cache. Wait until it has finished, or stop it, then run php artisan cms:build again.',
            $directory,
        ));
    } finally {
        registryLockRelease($lock);
    }

    expect(intdiv(hrtime(true) - $started, 1_000_000))->toBeGreaterThanOrEqual(50)
        ->and(RegistryFixtures::hashes($directory))->toBe($before)
        ->and(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
        ->and(RegistryFixtures::cache($directory)->read())->toEqual(registryOfPackage('acme/first-build'));
});

it('tries once without waiting when it is told to wait 0 ms', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    $lock = registryLockHeld($directory);

    try {
        expect(fn () => new FileRegistryCache($directory, new RegistryCacheCodec, 0)->write(CompiledRegistry::empty()))
            ->toThrow(RegistryCacheUnwritable::class, 'for more than 0 ms');
    } finally {
        registryLockRelease($lock);
    }

    new FileRegistryCache($directory, new RegistryCacheCodec, 0)->write(registryOfPackage('acme/after'));

    expect(RegistryFixtures::cache($directory)->read())->toEqual(registryOfPackage('acme/after'));
});

it('waits for a lock that is released within the wait, then writes', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    // The other write finishes in a child process that holds the lock for 200 ms.
    $root = dirname(__DIR__, 4);
    $holder = new Process([PHP_BINARY, '-r', sprintf(
        '$l = fopen(%s, "c"); flock($l, LOCK_EX); echo "held"; usleep(200000); flock($l, LOCK_UN);',
        var_export($directory.'/'.FileRegistryCache::LOCK_FILE, true),
    )], $root);
    $holder->start();
    $holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'held'));

    RegistryFixtures::cache($directory)->write(registryOfPackage('acme/after'));
    $holder->wait();

    expect($holder->getExitCode())->toBe(0)
        ->and(RegistryFixtures::cache($directory)->read())->toEqual(registryOfPackage('acme/after'));
});

it('releases the lock when the write is done, and when it fails', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(registryLockFree($directory))->toBeTrue();

    Assert::assertTrue(unlink($directory.'/hooks.php'));
    Assert::assertTrue(mkdir($directory.'/hooks.php'));

    expect(fn () => RegistryFixtures::cache($directory)->write(CompiledRegistry::empty()))
        ->toThrow(RegistryCacheUnwritable::class, $directory.'/hooks.php')
        ->and(registryLockFree($directory))->toBeTrue();
});

it('keeps its lock file when it removes the files it does not write, so a waiting write locks the same file', function (): void {
    $directory = RegistryFixtures::scratch();
    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    $inode = fileinode($directory.'/'.FileRegistryCache::LOCK_FILE);

    RegistryFixtures::cache($directory)->write(registryOfPackage('acme/after'));

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'commands.php', 'hooks.php'])
        ->and(fileinode($directory.'/'.FileRegistryCache::LOCK_FILE))->toBe($inode);
});

it('reports a lock file it cannot create with the reason, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    chmod($directory, 0o555);

    try {
        RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
        Assert::fail('The cache was written to a read-only directory.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())
            ->toStartWith('[registry_cache_unwritable] Could not write the registry cache file '.$directory.'/.lock: ')
            ->toContain('Permission denied');
    } finally {
        chmod($directory, 0o755);
    }

    expect(RegistryFixtures::files($directory))->toBe([]);
})->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root may write to a read-only directory');

it('refuses a negative wait for the lock', function (): void {
    expect(fn (): FileRegistryCache => new FileRegistryCache(RegistryFixtures::scratch(), new RegistryCacheCodec, -1))
        ->toThrow(InvalidArgumentException::class, 'The registry cache waits 0 ms or more for its lock, not -1 ms.');
});
