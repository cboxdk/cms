<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use InvalidArgumentException;

/**
 * The address of the page that resets a password (PRD 5.16), which a reset link points at with the
 * token as its last path segment. It comes from the operator's configuration, never from a request,
 * so a forged Host header cannot send a link elsewhere.
 *
 * It is an https URL, or an http URL of a loopback host (localhost, a name below .localhost,
 * 127.0.0.1 and [::1]) for development, with a host and optionally a port and a path, without
 * credentials, a query or a fragment, at most MAX_LENGTH characters; a trailing slash is dropped.
 */
#[Internal]
final readonly class ResetPage
{
    public const int MAX_LENGTH = 2000;

    private const string PATTERN = '~\Ahttps?://[^/?#\s@]+(?:/[^?#\s]*)?\z~';

    public string $value;

    /**
     * @throws InvalidArgumentException when the address is not of that form
     */
    public function __construct(string $value)
    {
        $value = rtrim($value, '/');

        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1 || ! $this->schemeFits($value)) {
            throw new InvalidArgumentException('The page of a password reset is an https URL without credentials, a query or a fragment, or an http URL of a loopback host.');
        }

        $this->value = $value;
    }

    /**
     * The link that carries the token to the page. It holds the token: never log or store it.
     */
    public function link(PasswordResetToken $token): string
    {
        return $this->value.'/'.$token->reveal();
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

        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1' || $host === '[::1]';
    }
}
