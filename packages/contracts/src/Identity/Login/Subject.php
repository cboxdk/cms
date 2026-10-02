<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The subject an issuer knows a person by (PRD 5.16), the sub claim of OpenID Connect: 1 to
 * MAX_LENGTH visible ASCII characters, compared exactly. It is unique only within its issuer, so
 * an IdP identity is the connection, the issuer and the subject together; an email address is
 * never a subject.
 */
#[Experimental]
final readonly class Subject
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not a subject
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('subject', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
