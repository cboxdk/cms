<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleSecret;
use Cbox\Cms\Identity\Login\Domain\InvalidLoginThrottle;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the ThrottleSecret from the application key, `app.key` (PRD 5.16, 12.2): the key as Laravel
 * writes it, `base64:` and the key's bytes in base64, or the bytes themselves, of at least
 * ThrottleSecret::MIN_KEY_BYTES bytes.
 */
#[Internal]
final readonly class ThrottleSecretConfig
{
    public const string KEY = 'app.key';

    private const string BASE64 = 'base64:';

    /**
     * @throws InvalidLoginThrottle when the application key is missing, not valid base64 or too short
     */
    public static function read(Repository $config): ThrottleSecret
    {
        $key = $config->get(self::KEY);
        $bytes = is_string($key) ? self::bytes($key) : null;

        if ($bytes !== null) {
            try {
                return ThrottleSecret::fromApplicationKey($bytes);
            } catch (InvalidArgumentException) {
                // Too short; reported below as any other invalid key.
            }
        }

        throw InvalidLoginThrottle::key(self::KEY, sprintf(
            'the application key, base64: and at least %d bytes in base64, as php artisan key:generate writes it',
            ThrottleSecret::MIN_KEY_BYTES,
        ));
    }

    private static function bytes(string $key): ?string
    {
        if (! str_starts_with($key, self::BASE64)) {
            return $key;
        }

        $bytes = base64_decode(substr($key, strlen(self::BASE64)), true);

        return is_string($bytes) ? $bytes : null;
    }
}
