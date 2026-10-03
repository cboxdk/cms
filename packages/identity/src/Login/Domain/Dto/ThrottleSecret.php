<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The key the login and reset throttles hash identifiers and IP addresses with (PRD 5.16, 12.2).
 * A plain SHA-256 of an email or an IPv4 address is reversed by hashing every address or a list of
 * known emails, so the throttles key their counts with HMAC-SHA-256 under this secret instead:
 * someone who reads Valkey, or a dump of it, learns nothing without the application's key.
 *
 * It is derived from the application key (`app.key`) as HMAC-SHA-256 of CONTEXT under the key's
 * bytes, so the throttle never uses the key that encrypts the cookies itself. A new application key
 * gives a new secret, which starts every count again. The secret never prints, dumps or serialises.
 */
#[Internal]
final readonly class ThrottleSecret
{
    public const string CONTEXT = 'cbox-cms.identity.throttle.v1';

    /**
     * The fewest bytes an application key has: Laravel's AES-128 key.
     */
    public const int MIN_KEY_BYTES = 16;

    private function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {}

    /**
     * @param  string  $applicationKey  the application key's bytes, decoded from `base64:` when it is written so
     *
     * @throws InvalidArgumentException when the key has fewer than MIN_KEY_BYTES bytes
     */
    public static function fromApplicationKey(#[SensitiveParameter] string $applicationKey): self
    {
        if (strlen($applicationKey) < self::MIN_KEY_BYTES) {
            throw new InvalidArgumentException(sprintf('An application key has at least %d bytes.', self::MIN_KEY_BYTES));
        }

        return new self(hash_hmac('sha256', self::CONTEXT, $applicationKey, true));
    }

    /**
     * The HMAC-SHA-256 of the value under the secret, as 64 lowercase hex digits.
     */
    public function hash(#[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '(secret)'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('The throttle secret is never serialised.');
    }
}
