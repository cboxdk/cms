<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Login;

use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleSecret;

/**
 * The throttle secret of the login tests: one derived from a fixed application key of 32 bytes, so
 * the keys of one identifier and address are the same in every test.
 */
final class ThrottleSecrets
{
    public const string APPLICATION_KEY = 'a fixed application key of bytes';

    public static function fixed(): ThrottleSecret
    {
        return ThrottleSecret::fromApplicationKey(self::APPLICATION_KEY);
    }
}
