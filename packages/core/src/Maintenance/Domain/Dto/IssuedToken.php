<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\IssuedCredential;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * A service credential cms:credential:issue issued: the token, shown once, and the credential as
 * the store keeps it, without the token.
 */
#[Internal]
final readonly class IssuedToken
{
    public function __construct(
        public TransportCredential $token,
        public IssuedCredential $credential,
    ) {}
}
