<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleSettings;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleLimit;
use Cbox\Cms\Identity\Login\Domain\InvalidLoginThrottle;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the limits of the login throttle from `cbox-cms.identity.login.throttle` (PRD 5.16): for
 * `identifier` and for `ip`, the `attempts` that may fail within `window_seconds`.
 */
#[Internal]
final readonly class LoginThrottleConfig
{
    public const string KEY = IdentityConfig::KEY.'.login.throttle';

    /**
     * @throws InvalidLoginThrottle when a limit is missing or out of its range
     */
    public static function read(Repository $config): LoginThrottleSettings
    {
        return new LoginThrottleSettings(self::limit($config, 'identifier'), self::limit($config, 'ip'));
    }

    /**
     * @throws InvalidLoginThrottle
     */
    private static function limit(Repository $config, string $scope): ThrottleLimit
    {
        $key = self::KEY.'.'.$scope;
        $attempts = $config->get($key.'.attempts');
        $window = $config->get($key.'.window_seconds');

        if (is_int($attempts) && is_int($window)) {
            try {
                return new ThrottleLimit($attempts, $window);
            } catch (InvalidArgumentException) {
                // Out of its range; reported below as any other invalid limit.
            }
        }

        throw InvalidLoginThrottle::key($key, sprintf(
            'a map with attempts, a whole number of 1 to %d, and window_seconds, a whole number of 1 to %d',
            ThrottleLimit::MAX_ATTEMPTS,
            ThrottleLimit::MAX_WINDOW_SECONDS,
        ));
    }
}
