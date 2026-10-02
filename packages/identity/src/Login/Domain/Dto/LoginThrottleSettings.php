<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;

/**
 * The limits of the login throttle (PRD 5.16), from `cbox-cms.identity.login.throttle`: one per
 * login identifier and one per IP address.
 */
#[Internal]
final readonly class LoginThrottleSettings
{
    public function __construct(
        public ThrottleLimit $identifier,
        public ThrottleLimit $ip,
    ) {}

    public function limit(ThrottleScope $scope): ThrottleLimit
    {
        return match ($scope) {
            ThrottleScope::Identifier => $this->identifier,
            ThrottleScope::Ip => $this->ip,
        };
    }
}
