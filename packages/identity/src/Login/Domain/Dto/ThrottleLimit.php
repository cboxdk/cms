<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * How many login attempts one key of a scope may make in a window (PRD 5.16): at most $attempts
 * attempts that did not log in, counted from the first of them for $windowSeconds seconds. The
 * attempt after the last allowed one is refused until the window ends.
 */
#[Internal]
final readonly class ThrottleLimit
{
    public const int MAX_ATTEMPTS = 10000;

    public const int MAX_WINDOW_SECONDS = 86400;

    /**
     * @throws InvalidArgumentException when the attempts are not 1 to MAX_ATTEMPTS or the window not 1 to MAX_WINDOW_SECONDS seconds
     */
    public function __construct(
        public int $attempts,
        public int $windowSeconds,
    ) {
        if ($attempts < 1 || $attempts > self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException(sprintf('A login throttle allows 1 to %d attempts in its window.', self::MAX_ATTEMPTS));
        }

        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException(sprintf('A login throttle\'s window is 1 to %d seconds.', self::MAX_WINDOW_SECONDS));
        }
    }
}
