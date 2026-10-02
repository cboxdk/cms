<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Identity\LocalAccounts\Domain\Dto\Argon2idParameters;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the settings of the local accounts (PRD 5.16): the Argon2id parameters of
 * `cbox-cms.identity.passwords.argon2id` and the issuer of the local connection,
 * `cbox-cms.identity.local.issuer`, which is the application's URL, `app.url`, when it is null.
 */
#[Internal]
final readonly class LocalAccountsConfig
{
    public const string ARGON2ID = 'cbox-cms.identity.passwords.argon2id';

    public const string ISSUER = 'cbox-cms.identity.local.issuer';

    public const string APP_URL = 'app.url';

    /**
     * @throws InvalidArgumentException when a parameter is not a whole number within its bounds
     */
    public static function argon2id(Repository $config): Argon2idParameters
    {
        $memory = $config->get(self::ARGON2ID.'.memory_kib', Argon2idParameters::DEFAULT_MEMORY_KIB);
        $time = $config->get(self::ARGON2ID.'.time', Argon2idParameters::DEFAULT_TIME);

        if (! is_int($memory) || ! is_int($time)) {
            throw new InvalidArgumentException(sprintf('%s.memory_kib and %s.time are whole numbers.', self::ARGON2ID, self::ARGON2ID));
        }

        return new Argon2idParameters($memory, $time);
    }

    /**
     * The issuer of the local connection: the setting, or the application's URL without a
     * trailing slash.
     *
     * @throws InvalidIdentity when neither is an issuer: an https URL, or an http URL of a loopback host
     */
    public static function issuer(Repository $config): Issuer
    {
        $issuer = $config->get(self::ISSUER) ?? $config->get(self::APP_URL);

        if (! is_string($issuer)) {
            throw InvalidIdentity::loginValue(self::ISSUER, 'an issuer, or null with app.url set to the application\'s URL');
        }

        return new Issuer(rtrim($issuer, '/'));
    }
}
