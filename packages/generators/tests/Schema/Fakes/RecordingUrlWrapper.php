<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

/**
 * A stream wrapper that stands in for http:// and ftp://: it serves one file and records each call.
 *
 * It is registered without STREAM_IS_URL. PHP refuses a URL wrapper before calling it when
 * allow_url_fopen is off, as it is in the php container (docker/php/conf.d/cms.ini), and a test
 * that expects no call would then pass whatever the code under test did. LocalFile refuses every
 * wrapper by the form of the path, not only URL wrappers, so the flag does not change what it sees.
 */
final class RecordingUrlWrapper
{
    public const string SCHEME = 'cmslocalfileprobe';

    /** @var list<string> */
    public static array $calls = [];

    /** @var resource|null */
    public $context;

    private int $position = 0;

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
     * @return array<int|string, int>
     */
    public function url_stat(string $path): array
    {
        self::$calls[] = 'url_stat '.$path;

        return ['mode' => 0o100644, 'size' => 11];
    }

    public function stream_open(string $path): bool
    {
        self::$calls[] = 'stream_open '.$path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr('remote: yes', $this->position, $count);
        $this->position += strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 11;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0o100644, 'size' => 11];
    }
}
