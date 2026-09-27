<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use InvalidArgumentException;

/**
 * The arguments of `composer docs:check -- [--root=<dir>]`: the tree to check, by default the
 * repository the script belongs to.
 */
final readonly class DocsCheckOptions
{
    public const string USAGE = 'Usage: php tools/bin/docs-check.php [--root=<dir>]';

    private function __construct(public ?string $root) {}

    /**
     * @param  list<string>  $arguments
     */
    public static function parse(array $arguments): self
    {
        $root = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--root=') && strlen($argument) > strlen('--root=') && $root === null) {
                $root = substr($argument, strlen('--root='));
            } else {
                throw new InvalidArgumentException("Unknown or repeated argument [{$argument}].");
            }
        }

        return new self($root);
    }
}
