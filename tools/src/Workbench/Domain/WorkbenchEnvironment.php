<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Workbench\Domain;

use InvalidArgumentException;

/**
 * The workbench's settings file, workbench/.env, and the application key in it, which the panel
 * needs: Laravel encrypts its session and CSRF cookies with APP_KEY and refuses a web request
 * without one, and the login's throttle derives its secret from it. workbench/.env.example ships
 * with an empty key, because a key is a secret of each machine, and git ignores workbench/.env.
 *
 * `composer dev:prepare` gives the file a key once (tools/bin/workbench-env.php): from the example
 * when the file is missing, and into the file when its key is missing or empty. A file with a key
 * is never changed, so the sessions of the served workbench survive a second run.
 */
final readonly class WorkbenchEnvironment
{
    /** The settings file, relative to the checkout. */
    public const string FILE = 'workbench/.env';

    /** The file it is made from, relative to the checkout. */
    public const string EXAMPLE = 'workbench/.env.example';

    /**
     * The settings file of Testbench's Laravel application, relative to the checkout. Testbench
     * copies workbench/.env there only when the application has none, so a key written later
     * would not reach it; tools/bin/workbench-env.php copies the file there itself.
     */
    public const string APPLICATION_FILE = 'vendor/orchestra/testbench-core/laravel/.env';

    /** A key line with a value that is neither empty nor an empty pair of quotes. */
    private const string KEY_PATTERN = '/^APP_KEY=(?!\s*$)(?!""\s*$)(?!\'\'\s*$).+$/m';

    /**
     * A new application key in the form `php artisan key:generate` writes for AES-256-CBC:
     * `base64:` and 32 random bytes in base64.
     */
    public static function newKey(string $bytes): string
    {
        if (strlen($bytes) !== 32) {
            throw new InvalidArgumentException('An application key for AES-256-CBC is 32 bytes, not '.strlen($bytes).'.');
        }

        return 'base64:'.base64_encode($bytes);
    }

    /**
     * Whether the settings have an application key.
     */
    public static function hasKey(string $settings): bool
    {
        return preg_match(self::KEY_PATTERN, $settings) === 1;
    }

    /**
     * The settings file with the key, or null when the current file has a key already and stays as
     * it is. Without a current file it is the example with the key; a current file without a key
     * gets it on its empty APP_KEY line, or on a line of its own at the end.
     */
    public static function withKey(string $example, ?string $current, string $key): ?string
    {
        if ($current !== null && self::hasKey($current)) {
            return null;
        }

        $settings = $current ?? $example;
        $line = 'APP_KEY='.$key;
        $replaced = preg_replace('/^APP_KEY=.*$/m', $line, $settings, 1, $count);

        if (! is_string($replaced) || $count === 0) {
            return rtrim($settings, "\n")."\n".$line."\n";
        }

        return $replaced;
    }
}
