<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SameSite;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the session cookie of an environment from `cbox-cms.identity.session.cookie` (PRD 5.16): a
 * map from an environment's name to its cookie, `name`, `secure` and `same_site`. An environment
 * without an entry takes the entry of `production`, so a staging or any other environment gets the
 * production cookie unless the application says otherwise.
 */
#[Internal]
final readonly class SessionCookieConfig
{
    public const string KEY = IdentityConfig::KEY.'.session.cookie';

    public const string FALLBACK = 'production';

    /**
     * @throws InvalidSessionCookie when the setting or the environment's entry is not as described
     */
    public static function read(Repository $config, string $environment): SessionCookie
    {
        $cookies = $config->get(self::KEY);

        if (! is_array($cookies)) {
            throw InvalidSessionCookie::key('', 'a map from an environment to its cookie');
        }

        $entry = array_key_exists($environment, $cookies) ? $environment : self::FALLBACK;
        $cookie = $cookies[$entry] ?? null;

        if (! is_array($cookie)) {
            throw InvalidSessionCookie::key($entry, 'a map with name, secure and same_site');
        }

        $unknown = array_diff(array_map(strval(...), array_keys($cookie)), ['name', 'secure', 'same_site']);

        if ($unknown !== []) {
            throw InvalidSessionCookie::key($entry.'.'.reset($unknown), 'left out; an entry has only name, secure and same_site');
        }

        $name = $cookie['name'] ?? null;
        $secure = $cookie['secure'] ?? null;
        $sameSite = is_string($cookie['same_site'] ?? null) ? SameSite::tryFrom($cookie['same_site']) : null;

        if (! is_string($name)) {
            throw InvalidSessionCookie::key($entry.'.name', 'a cookie name');
        }

        if (! is_bool($secure)) {
            throw InvalidSessionCookie::key($entry.'.secure', 'true or false');
        }

        if (! $sameSite instanceof SameSite) {
            throw InvalidSessionCookie::key($entry.'.same_site', 'lax, strict or none');
        }

        return new SessionCookie($name, $secure, $sameSite, $entry);
    }
}
