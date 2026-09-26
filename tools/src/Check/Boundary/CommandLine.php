<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

/**
 * The arguments a tool script was started with, after the script's own name.
 */
final readonly class CommandLine
{
    /**
     * @return list<string>
     */
    public static function arguments(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv)
            ? array_values(array_filter(array_slice($argv, 1), is_string(...)))
            : [];
    }
}
