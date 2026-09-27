<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

/**
 * A stream wrapper that stands in for ftp:// and http://: it records every call PHP makes to it
 * and fails each one, so a test sees whether code handed a path to a stream wrapper at all
 * (GUARDRAILS 3).
 *
 * It is registered without STREAM_IS_URL. PHP refuses a URL wrapper before calling it when
 * allow_url_fopen is off, as it is in the php container (docker/php/conf.d/cms.ini), and a test
 * that expects no call would then pass whatever the code under test did. The writers refuse every
 * wrapper by the form of the path (LocalPath), not only URL wrappers.
 */
final class RecordingStreamWrapper
{
    public const string SCHEME = 'cmsrecordingprobe';

    /** @var list<string> */
    public static array $calls = [];

    /** @var resource|null */
    public $context;

    /**
     * Registers the wrapper, in every allow_url_fopen setting, and forgets the calls recorded before.
     */
    public static function register(): void
    {
        self::$calls = [];

        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * A URL the wrapper serves, below a host that stands for a machine elsewhere.
     */
    public static function url(string $path = ''): string
    {
        return self::SCHEME.'://files.example.internal'.$path;
    }

    public function url_stat(string $path): false
    {
        self::$calls[] = 'url_stat '.$path;

        return false;
    }

    public function stream_open(string $path): bool
    {
        self::$calls[] = 'stream_open '.$path;

        return false;
    }

    public function mkdir(string $path): bool
    {
        self::$calls[] = 'mkdir '.$path;

        return false;
    }

    public function rename(string $from, string $to): bool
    {
        self::$calls[] = 'rename '.$from.' '.$to;

        return false;
    }

    public function unlink(string $path): bool
    {
        self::$calls[] = 'unlink '.$path;

        return false;
    }

    public function rmdir(string $path): bool
    {
        self::$calls[] = 'rmdir '.$path;

        return false;
    }

    public function dir_opendir(string $path): bool
    {
        self::$calls[] = 'dir_opendir '.$path;

        return false;
    }

    public function stream_metadata(string $path): bool
    {
        self::$calls[] = 'stream_metadata '.$path;

        return false;
    }
}
