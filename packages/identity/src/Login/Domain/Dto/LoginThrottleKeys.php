<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use SensitiveParameter;

/**
 * What one login attempt is counted under (PRD 5.16): the HMAC-SHA-256 under the ThrottleSecret of
 * the login identifier, in lower case as LoginIdentifier holds it, and of the client's address in
 * its canonical text (ClientAddress), each as 64 lowercase hex digits. The throttle never holds an
 * identifier or an address, which are personal data (PRD 12.2), only their keyed hashes, which
 * cannot be reversed by hashing every address or a list of emails without the secret.
 */
#[Internal]
final readonly class LoginThrottleKeys
{
    private function __construct(
        public string $identifier,
        public string $ip,
    ) {}

    public static function of(ThrottleSecret $secret, #[SensitiveParameter] LoginIdentifier $identifier, #[SensitiveParameter] ClientAddress $address): self
    {
        return new self($secret->hash($identifier->value), $secret->hash($address->value));
    }

    public function key(ThrottleScope $scope): string
    {
        return match ($scope) {
            ThrottleScope::Identifier => $this->identifier,
            ThrottleScope::Ip => $this->ip,
        };
    }
}
