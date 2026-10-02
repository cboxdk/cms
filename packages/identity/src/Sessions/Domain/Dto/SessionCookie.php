<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SameSite;

/**
 * The cookie that carries the session id (PRD 5.16), as the environment's entry of
 * `cbox-cms.identity.session.cookie` sets it: its name, whether it is Secure, and its SameSite. It
 * is always HttpOnly, so no script reads the session id, and always set for the path / without a
 * Domain, so it is sent only to the host that set it.
 *
 * Outside local and testing the cookie must be safe (insecurities() is empty): Secure, named with
 * the __Host- prefix, which a browser accepts only from HTTPS with path / and no Domain, and never
 * SameSite=None. Local and testing may leave Secure and the prefix off, because the workbench and
 * the browser tests serve plain HTTP on 127.0.0.1.
 */
#[Internal]
final readonly class SessionCookie
{
    public const string PRODUCTION_NAME = '__Host-cms_session';

    public const string DEVELOPMENT_NAME = 'cms_session';

    public const string HOST_PREFIX = '__Host-';

    public const string PATH = '/';

    public const bool HTTP_ONLY = true;

    /**
     * The environments that may use a cookie without Secure, the prefix or both.
     *
     * @var list<string>
     */
    public const array DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /** A cookie name is an RFC 6265 token: visible ASCII but separators. */
    private const string NAME_PATTERN = '/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]{1,128}\z/';

    /**
     * @throws InvalidSessionCookie when the name is not a cookie token, a __Host- name is not
     *                              Secure, or SameSite=None is not Secure
     */
    public function __construct(
        public string $name,
        public bool $secure,
        public SameSite $sameSite,
        string $key = '<environment>',
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidSessionCookie::key($key.'.name', 'a cookie name of 1 to 128 visible ASCII characters without separators');
        }

        if (! $secure && str_starts_with($name, self::HOST_PREFIX)) {
            throw InvalidSessionCookie::key($key.'.secure', 'true for a name with the __Host- prefix, which a browser accepts only as a Secure cookie');
        }

        if (! $secure && $sameSite === SameSite::None) {
            throw InvalidSessionCookie::key($key.'.same_site', 'lax or strict for a cookie that is not Secure, because a browser refuses SameSite=None without Secure');
        }
    }

    public static function isDevelopment(string $environment): bool
    {
        return in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true);
    }

    /**
     * Why the cookie is not safe outside local and testing, one reason each; empty when it is.
     *
     * @return list<string>
     */
    public function insecurities(): array
    {
        $reasons = [];

        if (! $this->secure) {
            $reasons[] = 'it is not Secure, so a browser sends it over plain HTTP';
        }

        if (! str_starts_with($this->name, self::HOST_PREFIX)) {
            $reasons[] = 'its name lacks the __Host- prefix, so another host of the domain could set it';
        }

        if ($this->sameSite === SameSite::None) {
            $reasons[] = 'it is SameSite=None, so a browser sends it with requests other sites start';
        }

        return $reasons;
    }

    /**
     * Whether the cookie may be used in the environment: always in local and testing, elsewhere
     * only when it has no insecurities.
     */
    public function safeIn(string $environment): bool
    {
        return self::isDevelopment($environment) || $this->insecurities() === [];
    }
}
