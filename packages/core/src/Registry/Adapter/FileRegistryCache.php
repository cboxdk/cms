<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use InvalidArgumentException;
use Throwable;

/**
 * The registry cache as PHP files in one directory, bootstrap/cache/cms/ in an application
 * (PRD 13.2). PHP files, so OPcache keeps them in memory and reading them costs no parsing.
 *
 * A write puts every file in a temporary file in the same directory first and then renames them
 * over the old ones, one after the other, so a reader sees either the old or the new version of
 * each file, but can meet the new actions.php next to the old hooks.php. The files carry the build
 * they come from (RegistryCacheCodec), and read() does not mix builds: when the files it loaded come
 * from different builds, it tells OPcache to forget them and loads them again, up to READ_ATTEMPTS
 * times with READ_PAUSE_MICROSECONDS between, so it gives the whole old or the whole new registry.
 * Files that stay mixed, as a build that stopped between two renames leaves them, are
 * MalformedRegistryCache until cms:build runs again.
 *
 * Writes run one at a time. Two builds that both finish, such as a deploy's cms:build next to the
 * one composer's post-autoload-dump runs, would otherwise interleave their renames and leave the
 * files of two builds for good. A write holds an exclusive flock() on LOCK_FILE in the directory
 * from before its first rename until it has removed the other files, and waits for it at most
 * $lockWaitMilliseconds of real time (LOCK_WAIT_MILLISECONDS unless the constructor is told
 * otherwise), polling with a growing sleep; a write that does not get it in time writes nothing
 * and throws RegistryCacheUnwritable. The operating system releases the lock when a process exits,
 * also when it stops partway. Reads take no lock. The lock file stays in the directory: removing it
 * while another write has it open would let a third write lock a new file and run next to it.
 *
 * The cache owns its directory. After the files are in place, every other file there is removed,
 * so a registry an earlier version wrote, or a file someone put there, cannot linger. Three things
 * stay: the lock file, subdirectories, which the cache never writes, and the temporary file of a
 * registry that a write has not renamed yet.
 *
 * The cache writes only a local directory: write() refuses a directory that names a stream wrapper,
 * such as ftp://, before any file function sees it (GUARDRAILS 3).
 */
#[Internal]
final readonly class FileRegistryCache implements RegistryCache
{
    /** How many times read() loads the files when they come from different builds. */
    public const int READ_ATTEMPTS = 10;

    /** How long read() waits before it loads files from different builds again. */
    public const int READ_PAUSE_MICROSECONDS = 5_000;

    /** The file in the directory that a write holds an exclusive flock() on. */
    public const string LOCK_FILE = '.lock';

    /** How long a write waits, in real time, for another write to release the lock. */
    public const int LOCK_WAIT_MILLISECONDS = 30_000;

    /** The first and the longest sleep between two tries to take the lock. */
    private const int FIRST_BACKOFF_MICROSECONDS = 2_000;

    private const int MAX_BACKOFF_MICROSECONDS = 50_000;

    /**
     * @param  int  $lockWaitMilliseconds  how long a write waits for the lock; 0 tries once
     *
     * @throws InvalidArgumentException when $lockWaitMilliseconds is negative
     */
    public function __construct(
        private string $directory,
        private RegistryCacheCodec $codec,
        private int $lockWaitMilliseconds = self::LOCK_WAIT_MILLISECONDS,
    ) {
        if ($lockWaitMilliseconds < 0) {
            throw new InvalidArgumentException(sprintf('The registry cache waits 0 ms or more for its lock, not %d ms.', $lockWaitMilliseconds));
        }
    }

    public function location(): string
    {
        return $this->directory;
    }

    public function write(CompiledRegistry $registry): void
    {
        if (LocalPath::namesStreamWrapper($this->directory)) {
            throw RegistryCacheUnwritable::streamWrapper($this->directory);
        }

        $sources = $this->codec->encode($registry);

        if (! is_dir($this->directory)) {
            $failure = $this->attempt(fn (): bool => mkdir($this->directory, 0o775, true) || is_dir($this->directory), 'the directory could not be created');

            if ($failure !== null) {
                throw RegistryCacheUnwritable::at($this->directory, $failure);
            }
        }

        $lock = $this->lock();

        try {
            $this->replace($sources);
            $this->removeOthers();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(): CompiledRegistry
    {
        for ($attempt = 1; ; $attempt++) {
            $files = $this->load();

            if ($attempt >= self::READ_ATTEMPTS || ! $this->codec->fromDifferentBuilds($files)) {
                return $this->codec->decode($files, $this->directory);
            }

            // A worker's OPcache can keep a file of the old build after the new one is in place.
            foreach (RegistryName::cases() as $name) {
                $path = $this->path($name);
                $this->attempt(static fn (): bool => ! function_exists('opcache_invalidate') || opcache_invalidate($path, true), 'OPcache could not forget the file');
            }

            usleep(self::READ_PAUSE_MICROSECONDS);
        }
    }

    /**
     * What the file of each registry returns, keyed by registry name.
     *
     * @return array<string, mixed>
     *
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    private function load(): array
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

        return $files;
    }

    private function path(RegistryName $name): string
    {
        return $this->directory.'/'.$name->fileName();
    }

    /**
     * Opens LOCK_FILE, creating it when it is missing, and takes an exclusive flock() on it,
     * waiting while another write holds it, at most $lockWaitMilliseconds.
     *
     * @return resource the open lock file; closing it releases the lock
     *
     * @throws RegistryCacheUnwritable
     */
    private function lock()
    {
        $path = $this->directory.'/'.self::LOCK_FILE;
        $handle = null;
        $failure = $this->attempt(static function () use ($path, &$handle): bool {
            $opened = fopen($path, 'c');

            if ($opened === false) {
                return false;
            }

            $handle = $opened;

            return true;
        }, 'the lock file could not be opened');

        if ($failure !== null || ! is_resource($handle)) {
            throw RegistryCacheUnwritable::at($path, $failure ?? 'the lock file could not be opened');
        }

        $deadline = hrtime(true) + $this->lockWaitMilliseconds * 1_000_000;
        $backoff = self::FIRST_BACKOFF_MICROSECONDS;

        while (true) {
            $wouldBlock = 0;
            $taken = false;
            $failure = $this->attempt(static function () use ($handle, &$wouldBlock, &$taken): bool {
                $taken = flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);

                return $taken || $wouldBlock === 1;
            }, 'the lock could not be taken');

            if ($taken) {
                return $handle;
            }

            $remaining = intdiv($deadline - hrtime(true), 1_000);

            if ($failure !== null || $remaining <= 0) {
                fclose($handle);

                throw $failure !== null
                    ? RegistryCacheUnwritable::at($path, $failure)
                    : RegistryCacheUnwritable::locked($path, $this->lockWaitMilliseconds);
            }

            usleep(min($backoff, $remaining));
            $backoff = min($backoff * 2, self::MAX_BACKOFF_MICROSECONDS);
        }
    }

    /**
     * Removes every file in the directory that is not the lock file, the file of a registry or the
     * temporary file of one.
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
        $keep = '/\A(?:(?:'.implode('|', $names).')(?:\.[0-9a-f]{16}\.tmp)?|'.preg_quote(self::LOCK_FILE, '/').')\z/';

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

    /**
     * Writes every file to a temporary file and then renames them into place in the order of
     * RegistryName, so the files of a build change within a few renames of each other. A failure
     * removes the temporary files that are left.
     *
     * @param  array<string, string>  $sources  the PHP source of each file, keyed by registry name
     *
     * @throws RegistryCacheUnwritable
     */
    private function replace(array $sources): void
    {
        $temporaries = [];

        try {
            foreach (RegistryName::cases() as $name) {
                $path = $this->path($name);
                $source = $sources[$name->value];
                $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));
                $temporaries[$name->value] = $temporary;

                $failure = $this->attempt(static fn (): bool => file_put_contents($temporary, $source) === strlen($source), 'the file could not be written');

                if ($failure !== null) {
                    throw RegistryCacheUnwritable::at($path, $failure);
                }
            }

            foreach ($temporaries as $registry => $temporary) {
                $path = $this->path(RegistryName::from($registry));

                $failure = $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

                if ($failure !== null) {
                    throw RegistryCacheUnwritable::at($path, $failure);
                }

                unset($temporaries[$registry]);
            }
        } finally {
            foreach ($temporaries as $temporary) {
                $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');
            }
        }

        // A long-running worker keeps the old files in OPcache until it is told otherwise.
        if (function_exists('opcache_invalidate')) {
            foreach (RegistryName::cases() as $name) {
                opcache_invalidate($this->path($name), true);
            }
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
