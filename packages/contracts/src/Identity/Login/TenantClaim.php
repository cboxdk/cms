<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The name of the claim of a verified token that carries the tenant (PRD 5.16), such as tid for
 * Microsoft Entra ID or hd for Google: a letter, then letters, digits and underscores, at most
 * MAX_LENGTH characters. The tenant is read from this claim of the token, never from a parameter of
 * the request.
 */
#[Experimental]
final readonly class TenantClaim
{
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/';

    /**
     * @throws InvalidIdentity when the name is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('tenant claim', 'a letter, then at most 63 letters, digits and underscores');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
