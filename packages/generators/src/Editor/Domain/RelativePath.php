<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * The relative path from a directory to a file, with forward slashes, as an editor line holds it.
 * Both paths are absolute and canonical, so a `..` in the result climbs the directory the path
 * names and not a symlink's target.
 */
#[Internal]
final readonly class RelativePath
{
    /**
     * @param  string  $directory  the absolute, canonical directory the path starts from
     * @param  string  $file  the absolute, canonical path of the file it leads to
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when a path is not absolute
     *                          and canonical, or the two lie on different drives
     */
    public static function between(string $directory, string $file): string
    {
        $from = self::segments($directory);
        $to = self::segments($file);

        if ($from[0] !== $to[0]) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The blueprint schema %s cannot be reached by a relative path from %s, because they lie on different drives. Install the application on the drive of its schema roots.',
                $file,
                $directory,
            ));
        }

        $common = 1;

        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return implode('/', [
            ...array_fill(0, count($from) - $common, '..'),
            ...array_slice($to, $common),
        ]);
    }

    /**
     * The segments of an absolute path, the first being the root: empty for `/`, the drive such as
     * `C:` on Windows.
     *
     * @return non-empty-list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    private static function segments(string $path): array
    {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('#\A(?:/|[A-Za-z]:/)#', $normalized) !== 1) {
            throw self::notCanonical($path);
        }

        $segments = explode('/', $normalized);
        $root = array_shift($segments);
        $below = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        foreach ($below as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw self::notCanonical($path);
            }
        }

        return [$root, ...$below];
    }

    private static function notCanonical(string $path): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('The path %s is not absolute and canonical.', $path));
    }
}
