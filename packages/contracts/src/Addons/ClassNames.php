<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Checks the class names and directories an addon manifest holds.
 */
#[Internal]
final readonly class ClassNames
{
    /** A fully qualified PHP class name without the leading backslash. */
    private const string PATTERN = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    /**
     * The class name without a leading backslash.
     *
     * @throws InvalidAddonManifest when it is not a class name
     */
    public static function check(string $what, string $class): string
    {
        $class = ltrim($class, '\\');

        if (preg_match(self::PATTERN, $class) !== 1) {
            throw InvalidAddonManifest::because(sprintf('The class name "%s" of %s is not a PHP class name. Use ::class, such as PublishNote::class.', $class, $what));
        }

        return $class;
    }

    /**
     * The directory, when it is an absolute path.
     *
     * @throws InvalidAddonManifest when it is not
     */
    public static function absoluteDirectory(string $what, string $directory): string
    {
        if (! str_starts_with($directory, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) !== 1) {
            throw InvalidAddonManifest::because(sprintf(
                'The %s directory "%s" is not an absolute path. Build it from __DIR__ in the service provider.',
                $what,
                $directory,
            ));
        }

        return $directory;
    }
}
