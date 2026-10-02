<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * A tenant of an issuer with several tenants (PRD 5.16), as the tenant claim carries it, such as
 * the directory id of Microsoft Entra ID or the Workspace domain of Google: 1 to MAX_LENGTH visible
 * ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class TenantId
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not a tenant
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('tenant', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
