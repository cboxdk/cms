<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a person types to log in with a local account (PRD 5.16, "Lokale konti"): the email address
 * of the account, in lower case. A local account is found by it, and two accounts never share one.
 *
 * It is 1 to MAX_LENGTH characters in lower case, without white space or control characters, as
 * the CHECK of cms_identity.local_accounts holds it. fromEmail() takes an email address and lowers
 * its case; typed() reads what a login form took, lowering its case and taking away white space at
 * either end, and gives null for anything else, so a login of a malformed identifier is refused as
 * any unknown login is. It is personal data, classified personal (PRD 12.2): no message, log
 * entry, span or event repeats it.
 */
#[Experimental]
final readonly class LoginIdentifier
{
    /** The longest identifier, in characters, as the longest email address. */
    public const int MAX_LENGTH = EmailAddress::MAX_LENGTH;

    private const string PATTERN = '/\A[^\s\x00-\x1F\x7F]+\z/u';

    /**
     * @throws InvalidIdentity when the value is not in lower case, is empty, too long or holds white space or a control character
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1
            || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH
            || mb_strtolower($value, 'UTF-8') !== $value) {
            throw InvalidIdentity::loginIdentifier();
        }
    }

    /**
     * The identifier of an email address: the address in lower case.
     */
    public static function fromEmail(EmailAddress $email): self
    {
        return new self(mb_strtolower($email->value, 'UTF-8'));
    }

    /**
     * The identifier a login form took, in lower case and without white space at either end, or
     * null when it is not one.
     */
    public static function typed(string $typed): ?self
    {
        $value = mb_strtolower(trim($typed), 'UTF-8');

        if (preg_match(self::PATTERN, $value) !== 1 || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            return null;
        }

        return new self($value);
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
