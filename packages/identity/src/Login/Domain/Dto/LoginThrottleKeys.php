<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use SensitiveParameter;

/**
 * What one login attempt is counted under (PRD 5.16): the SHA-256 of the login identifier, in lower
 * case as LoginIdentifier holds it, and the SHA-256 of the client's address in its canonical text
 * (ClientAddress), each as 64 lowercase hex digits. The throttle never holds an identifier or an
 * address, which are personal data (PRD 12.2), only their hashes.
 */
#[Internal]
final readonly class LoginThrottleKeys
{
    private function __construct(
        public string $identifier,
        public string $ip,
    ) {}

    public static function of(#[SensitiveParameter] LoginIdentifier $identifier, #[SensitiveParameter] ClientAddress $address): self
    {
        return new self(hash('sha256', $identifier->value), hash('sha256', $address->value));
    }

    public function key(ThrottleScope $scope): string
    {
        return match ($scope) {
            ThrottleScope::Identifier => $this->identifier,
            ThrottleScope::Ip => $this->ip,
        };
    }
}
