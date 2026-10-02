<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LocalAccounts\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The Argon2id parameters the local accounts hash their passwords with (PRD 5.16), from
 * cbox-cms.identity.passwords.argon2id: the memory in KiB, MIN_MEMORY_KIB to MAX_MEMORY_KIB, and
 * the number of passes, MIN_TIME to MAX_TIME. The hash always uses one thread, the only number
 * PHP's Argon2id from libsodium takes. The defaults are PHP's own, 64 MiB and 4 passes, above the
 * OWASP minimum of 19 MiB and 2 passes.
 */
#[Internal]
final readonly class Argon2idParameters
{
    public const int DEFAULT_MEMORY_KIB = 65536;

    public const int DEFAULT_TIME = 4;

    public const int MIN_MEMORY_KIB = 1024;

    public const int MAX_MEMORY_KIB = 4194304;

    public const int MIN_TIME = 1;

    public const int MAX_TIME = 64;

    public const int THREADS = 1;

    /**
     * @throws InvalidArgumentException when a parameter is outside its bounds
     */
    public function __construct(
        public int $memoryKib = self::DEFAULT_MEMORY_KIB,
        public int $time = self::DEFAULT_TIME,
    ) {
        if ($memoryKib < self::MIN_MEMORY_KIB || $memoryKib > self::MAX_MEMORY_KIB) {
            throw new InvalidArgumentException(sprintf('The Argon2id memory is %d to %d KiB, got %d.', self::MIN_MEMORY_KIB, self::MAX_MEMORY_KIB, $memoryKib));
        }

        if ($time < self::MIN_TIME || $time > self::MAX_TIME) {
            throw new InvalidArgumentException(sprintf('The Argon2id passes are %d to %d, got %d.', self::MIN_TIME, self::MAX_TIME, $time));
        }
    }
}
