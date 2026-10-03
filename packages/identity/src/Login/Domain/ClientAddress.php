<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use SensitiveParameter;

/**
 * The IP address a login or a request for a password reset came from (PRD 5.16), which the login
 * throttle counts attempts under. It is an IPv4 or IPv6 address in its canonical text, as
 * inet_ntop() writes it, so two spellings of one address, such as `2001:DB8::1` and
 * `2001:db8:0:0:0:0:0:1`, are one address and one count.
 *
 * It is personal data (PRD 12.2): var_dump() and a stack trace never show it, and no message
 * repeats it.
 */
#[Internal]
final readonly class ClientAddress
{
    public string $value;

    /**
     * @throws InvalidClientAddress when the text is empty or not an IPv4 or IPv6 address
     */
    public function __construct(#[SensitiveParameter] string $value)
    {
        $packed = filter_var($value, FILTER_VALIDATE_IP) === false ? false : inet_pton($value);
        $canonical = $packed === false ? false : inet_ntop($packed);

        if ($canonical === false) {
            throw InvalidClientAddress::notAnAddress();
        }

        $this->value = $canonical;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[personal]'];
    }
}
