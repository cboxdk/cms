<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Install\Boundary;

use InvalidArgumentException;

/**
 * The arguments of `composer install:check -- [--root=<dir>]`: the checkout to check, by default
 * the repository the script belongs to.
 */
final readonly class InstallCheckOptions
{
    public const string USAGE = 'Usage: php tools/bin/install-check.php [--root=<dir>]';

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
