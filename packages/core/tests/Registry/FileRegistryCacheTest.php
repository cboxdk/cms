<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Tests\Support\RecordingStreamWrapper;
use PHPUnit\Framework\Assert;

afterEach(function (): void {
    RecordingStreamWrapper::unregister();
    RegistryFixtures::cleanUp();
});

it('creates the directory and its parents when they do not exist', function (): void {
    $directory = RegistryFixtures::scratch();
    $cache = RegistryFixtures::cache($directory.'/bootstrap/cache/cms');

    $cache->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory.'/bootstrap/cache/cms'))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php'])
        ->and($cache->read())->toEqual(CompiledRegistry::empty())
        ->and($cache->location())->toBe($directory.'/bootstrap/cache/cms');
});

it('owns its directory: every file it does not write is removed, whatever its name', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/slots.php', "<?php return [];\n");
    file_put_contents($directory.'/notes.txt', 'left by hand');
    file_put_contents($directory.'/.hidden', 'left by hand');
    file_put_contents($directory.'/slots.php.0123456789abcdef.tmp', 'from an old build');
    file_put_contents($directory.'/commands.php.0123456789abcdef.tmp.bak', 'not a temporary file of a write');
    file_put_contents($directory."/hooks.php\n", 'a registry name with a line break after it');
    symlink($directory.'/notes.txt', $directory.'/link.php');
    symlink($directory.'/missing', $directory.'/dangling.php');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php']);
});

it('leaves subdirectories and the temporary file of a concurrent write in place', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory.'/nested', 0o775, true);
    file_put_contents($directory.'/nested/slots.php', "<?php return [];\n");
    file_put_contents($directory.'/hooks.php.0123456789abcdef.tmp', 'being written by another cms:build');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'hooks.php.0123456789abcdef.tmp', 'nested', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php'])
        ->and(RegistryFixtures::files($directory.'/nested'))->toBe(['slots.php']);
});

it('keeps the OpenAPI document cms:build writes next to it, and its temporary file, but not a file that only resembles it', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory, 0o775, true);
    file_put_contents($directory.'/openapi.json', "{}\n");
    file_put_contents($directory.'/openapi.json.0123456789abcdef.tmp', 'being written by another cms:build');
    file_put_contents($directory.'/openapi.yaml', 'openapi: 3.1.1');
    file_put_contents($directory.'/openapi.json.old', '{}');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'openapi.json', 'openapi.json.0123456789abcdef.tmp', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php']);
});

it('keeps the theme\'s stylesheet cms:build writes next to it, and its temporary file', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory, 0o775, true);
    file_put_contents($directory.'/theme.css', "@layer cms.theme {}\n");
    file_put_contents($directory.'/theme.css.0123456789abcdef.tmp', 'being written by another cms:build');
    file_put_contents($directory.'/theme.css.old', '');

    RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php', 'theme.css', 'theme.css.0123456789abcdef.tmp']);
});

it('replaces the files before it removes the others, so a failed write keeps what was there', function (): void {
    $directory = RegistryFixtures::scratch();
    mkdir($directory);
    file_put_contents($directory.'/slots.php', "<?php return [];\n");
    mkdir($directory.'/hooks.php');

    expect(fn () => RegistryFixtures::cache($directory)->write(CompiledRegistry::empty()))
        ->toThrow(RegistryCacheUnwritable::class, $directory.'/hooks.php');

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php', 'slots.php']);
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
    file_put_contents($directory.'/commands.php', "<?php return 'commands';\n");

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
    // The lock file of an earlier write, so the write gets as far as its first file.
    touch($directory.'/.lock');
    chmod($directory, 0o555);

    try {
        RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
        Assert::fail('The cache was written to a read-only directory.');
    } catch (RegistryCacheUnwritable $unwritable) {
        expect($unwritable->getMessage())->toContain($directory.'/actions.php')->toContain('Permission denied');
    } finally {
        chmod($directory, 0o755);
    }

    expect(RegistryFixtures::files($directory))->toBe(['.lock']);
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

    expect(RegistryFixtures::files($directory))->toBe(['.lock', 'actions.php', 'addons.php', 'commands.php', 'hooks.php']);
});

it('refuses to write to a directory that names a stream wrapper before it touches it, so it never writes to ftp:// (GUARDRAILS 3)', function (string $directory): void {
    RecordingStreamWrapper::register();

    $failure = null;

    try {
        RegistryFixtures::cache($directory)->write(CompiledRegistry::empty());
    } catch (RegistryCacheUnwritable $unwritable) {
        $failure = $unwritable;
    }

    expect(RecordingStreamWrapper::$calls)->toBe([])
        ->and($failure?->getMessage())->toBe('[registry_cache_unwritable] Could not write the registry cache to '.$directory.': the path names a stream wrapper, and the registry cache is written only to a local directory (GUARDRAILS 3). Give the application a local bootstrap path, then run php artisan cms:build again.');
})->with([
    'a URL wrapper' => RecordingStreamWrapper::url('/bootstrap/cache/cms'),
    'a URL wrapper in upper case' => strtoupper(RecordingStreamWrapper::SCHEME).'://files.example.internal/cms',
    'a URL behind a filter wrapper' => 'compress.zlib://'.RecordingStreamWrapper::url('/cms'),
    'file://' => 'file:///tmp/cms',
]);
