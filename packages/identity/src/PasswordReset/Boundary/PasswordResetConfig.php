<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Identity\PasswordReset\Domain\InvalidPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetPage;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the settings of the password reset from `cbox-cms.identity.password_reset` (PRD 5.16):
 * `token_minutes` and `url`, the page a link points at, which is the application's URL, `app.url`,
 * followed by DEFAULT_PATH when it is null. The throttle of the requests is read by
 * LoginThrottleConfig with its RESET_KEY.
 */
#[Internal]
final readonly class PasswordResetConfig
{
    public const string KEY = IdentityConfig::KEY.'.password_reset';

    public const string APP_URL = 'app.url';

    /** The path of the reset page where PanelRoutes mounts it with its default prefix. */
    public const string DEFAULT_PATH = '/cms/reset-password';

    /**
     * @throws InvalidPasswordReset when a setting is missing or not of its form
     */
    public static function read(Repository $config): ResetSettings
    {
        $minutes = $config->get(self::KEY.'.token_minutes', ResetSettings::DEFAULT_MINUTES);
        $url = $config->get(self::KEY.'.url');
        $app = $config->get(self::APP_URL);
        $page = is_string($url) ? $url : (is_string($app) && $url === null ? rtrim($app, '/').self::DEFAULT_PATH : null);

        if (! is_int($minutes)) {
            throw InvalidPasswordReset::key(self::KEY.'.token_minutes', sprintf('a whole number of %d to %d', ResetSettings::MIN_MINUTES, ResetSettings::MAX_MINUTES));
        }

        try {
            $resetPage = new ResetPage($page ?? '');
        } catch (InvalidArgumentException) {
            throw InvalidPasswordReset::key(self::KEY.'.url', 'an https URL without a query or a fragment, an http URL of a loopback host, or null with app.url set to the application\'s URL');
        }

        try {
            return new ResetSettings($minutes, $resetPage);
        } catch (InvalidArgumentException) {
            throw InvalidPasswordReset::key(self::KEY.'.token_minutes', sprintf('a whole number of %d to %d', ResetSettings::MIN_MINUTES, ResetSettings::MAX_MINUTES));
        }
    }
}
