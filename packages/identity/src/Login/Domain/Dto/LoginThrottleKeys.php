<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use SensitiveParameter;

/**
 * What one login attempt is counted under (PRD 5.16): the SHA-256 of the login identifier, in lower
 * case and without white space at either end, as LoginIdentifier::typed() reads it, and the
 * SHA-256 of the client's IP address, each as 64 lowercase hex digits. The throttle never holds an
 * identifier or an address, which are personal data (PRD 12.2), only their hashes.
 */
#[Internal]
final readonly class LoginThrottleKeys
{
    private function __construct(
        public string $identifier,
        public string $ip,
    ) {}

    public static function of(#[SensitiveParameter] string $identifier, #[SensitiveParameter] string $ip): self
    {
        return new self(
            hash('sha256', mb_strtolower(trim($identifier), 'UTF-8')),
            hash('sha256', $ip),
        );
    }

    public function key(ThrottleScope $scope): string
    {
        return match ($scope) {
            ThrottleScope::Identifier => $this->identifier,
            ThrottleScope::Ip => $this->ip,
        };
    }
}
