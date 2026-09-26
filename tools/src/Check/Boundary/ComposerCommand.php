<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

/**
 * The command that runs Composer. Inside a Composer script, Composer sets COMPOSER_BINARY to
 * itself, and it is run with the same PHP binary, as Composer does for `@composer`. Outside
 * one, `composer` from the PATH.
 */
final readonly class ComposerCommand
{
    /**
     * @return list<string>
     */
    public static function resolve(string $php): array
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? [$php, $binary] : ['composer'];
    }
}
