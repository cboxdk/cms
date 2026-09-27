<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Closure;
use LogicException;

/**
 * A stream wrapper over a real directory that runs a scheduled step just before a given open of a
 * file, so a test can put the renames of a concurrent cms:build between the files that
 * FileRegistryCache::read() loads, in an order it chooses. Opens are counted from 1 after over();
 * the step for open n runs before the nth file is opened. Everything else passes through to the
 * real file.
 */
final class InterleavedFiles
{
    public const string SCHEME = 'cms-interleaved';

    /** @var array<int, Closure(): void> */
    private static array $steps = [];

    private static int $opens = 0;

    /**
     * Set by PHP on every instance.
     *
     * @var resource|null
     */
    public $context;

    /** @var resource|null */
    private $handle;

    /**
     * Registers the wrapper and schedules the steps.
     *
     * @param  string  $directory  an absolute path
     * @param  array<int, Closure(): void>  $steps  keyed by the number of the open each runs before
     * @return string the directory as seen through the wrapper
     */
    public static function over(string $directory, array $steps): string
    {
        self::$steps = $steps;
        self::$opens = 0;

        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        return self::SCHEME.'://'.$directory;
    }

    public static function reset(): void
    {
        self::$steps = [];
        self::$opens = 0;

        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * How many files were opened since over().
     */
    public static function opens(): int
    {
        return self::$opens;
    }

    public function stream_open(string $path, string $mode): bool
    {
        self::$opens++;
        (self::$steps[self::$opens] ?? static function (): void {})();

        $real = $this->real($path);

        if (! is_file($real)) {
            return false;
        }

        $handle = fopen($real, $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle(), max(1, $count));
    }

    public function stream_eof(): bool
    {
        return feof($this->handle());
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle());
    }

    public function stream_set_option(): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path): array|false
    {
        $real = $this->real($path);

        return file_exists($real) ? stat($real) : false;
    }

    /**
     * @return resource
     */
    private function handle()
    {
        return $this->handle ?? throw new LogicException('The file is not open.');
    }

    private function real(string $path): string
    {
        return substr($path, strlen(self::SCHEME.'://'));
    }
}
