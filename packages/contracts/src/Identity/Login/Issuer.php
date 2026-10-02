<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The issuer of an assertion (PRD 5.16), as OpenID Connect Core 1.0 writes it in the iss claim: a
 * URL with the scheme https, a host, and optionally a port and a path, without a query or a
 * fragment, at most MAX_LENGTH characters. Compared exactly, as OpenID Connect compares it, so
 * "https://accounts.google.com" and "https://accounts.google.com/" are two issuers.
 *
 * The scheme http is taken only for a loopback host (localhost, a name below .localhost,
 * 127.0.0.1 and [::1]), so a local connection in development has an issuer too.
 */
#[Experimental]
final readonly class Issuer
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '~\Ahttps?://[^/?#\s@]+(?:/[^?#\s]*)?\z~';

    /**
     * @throws InvalidIdentity when the value is not an issuer
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH
            || preg_match(self::PATTERN, $value) !== 1
            || ! $this->schemeFits($value)) {
            throw InvalidIdentity::loginValue('issuer', 'an https URL without a query or a fragment, or an http URL of a loopback host, of at most 255 characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private function schemeFits(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if (str_starts_with($value, 'https://')) {
            return true;
        }

        $host = strtolower($host);

        return str_starts_with($value, 'http://')
            && ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1' || $host === '[::1]');
    }
}
