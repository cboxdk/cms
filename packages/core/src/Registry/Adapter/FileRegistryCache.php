<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Throwable;

/**
 * The registry cache as PHP files in one directory, bootstrap/cache/cms/ in an application
 * (PRD 13.2). PHP files, so OPcache keeps them in memory and reading them costs no parsing.
 *
 * Each file is written to a temporary file in the same directory and renamed over the old one, so
 * a request that reads the cache while cms:build runs sees either the old file or the new one.
 *
 * The cache owns its directory. After the files are in place, every other file there is removed,
 * so a registry an earlier version wrote, or a file someone put there, cannot linger. Two things
 * stay: subdirectories, which the cache never writes, and the temporary file of a registry that a
 * concurrent write has not renamed yet, because removing it would make that write fail.
 */
#[Internal]
final readonly class FileRegistryCache implements RegistryCache
{
    public function __construct(
        private string $directory,
        private RegistryCacheCodec $codec,
    ) {}

    public function location(): string
    {
        return $this->directory;
    }

    public function write(CompiledRegistry $registry): void
    {
        $sources = $this->codec->encode($registry);

        if (! is_dir($this->directory)) {
            $failure = $this->attempt(fn (): bool => mkdir($this->directory, 0o775, true) || is_dir($this->directory), 'the directory could not be created');

            if ($failure !== null) {
                throw RegistryCacheUnwritable::at($this->directory, $failure);
            }
        }

        foreach (RegistryName::cases() as $name) {
            $this->replace($this->path($name), $sources[$name->value]);
        }

        $this->removeOthers();
    }

    public function read(): CompiledRegistry
    {
        $files = [];

        foreach (RegistryName::cases() as $name) {
            $path = $this->path($name);

            if (! is_file($path)) {
                throw RegistryCacheMissing::at($path);
            }

            try {
                $files[$name->value] = (static fn (string $file): mixed => require $file)($path);
            } catch (Throwable $failure) {
                throw MalformedRegistryCache::at($path, '', sprintf('loading it failed: %s', $failure->getMessage()), $failure);
            }
        }

        return $this->codec->decode($files, $this->directory);
    }

    private function path(RegistryName $name): string
    {
        return $this->directory.'/'.$name->fileName();
    }

    /**
     * Removes every file in the directory that is not the file of a registry or the temporary file
     * of one that another write is renaming into place.
     *
     * @throws RegistryCacheUnwritable
     */
    private function removeOthers(): void
    {
        $directory = $this->directory;
        $entries = [];
        $failure = $this->attempt(static function () use ($directory, &$entries): bool {
            $listed = scandir($directory);

            if ($listed === false) {
                return false;
            }

            $entries = $listed;

            return true;
        }, 'the directory could not be listed');

        if ($failure !== null) {
            throw RegistryCacheUnwritable::removing($directory, $failure);
        }

        $names = array_map(static fn (RegistryName $name): string => preg_quote($name->fileName(), '/'), RegistryName::cases());
        $keep = '/\A(?:'.implode('|', $names).')(?:\.[0-9a-f]{16}\.tmp)?\z/';

        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;

            if ($entry === '.' || $entry === '..' || preg_match($keep, $entry) === 1 || (is_dir($path) && ! is_link($path))) {
                continue;
            }

            $failure = $this->attempt(static fn (): bool => unlink($path) || (! file_exists($path) && ! is_link($path)), 'the file could not be removed');

            if ($failure !== null) {
                throw RegistryCacheUnwritable::removing($path, $failure);
            }
        }
    }

    private function replace(string $path, string $source): void
    {
        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));

        $failure = $this->attempt(static fn (): bool => file_put_contents($temporary, $source) === strlen($source), 'the file could not be written')
            ?? $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw RegistryCacheUnwritable::at($path, $failure);
        }

        // A long-running worker keeps the old file in OPcache until it is told otherwise.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    /**
     * Runs a filesystem call and turns its warning into the reason it failed.
     *
     * @param  callable(): bool  $operation
     * @return string|null null when it succeeded, otherwise why it failed
     */
    private function attempt(callable $operation, string $fallback): ?string
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        return $succeeded ? null : ($warning ?? $fallback);
    }
}
