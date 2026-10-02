<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;

/**
 * The lifetimes of a session (PRD 5.16, "Loginpolitik"), in minutes: it ends after
 * $inactivityMinutes without a request, and $absoluteMinutes after the login whatever happens. The
 * inactivity timeout is never longer than the absolute lifetime.
 */
#[Internal]
final readonly class SessionLifetimes
{
    /**
     * @throws InvalidLoginPolicy when a lifetime is below one minute or the inactivity timeout is longer than the absolute lifetime
     */
    public function __construct(
        public int $inactivityMinutes,
        public int $absoluteMinutes,
        string $key = 'the session lifetimes',
    ) {
        if ($inactivityMinutes < 1) {
            throw InvalidLoginPolicy::key($key.'.inactivity_minutes', 'a whole number of minutes, 1 or more');
        }

        if ($absoluteMinutes < $inactivityMinutes) {
            throw InvalidLoginPolicy::key($key.'.absolute_minutes', 'a whole number of minutes, at least inactivity_minutes');
        }
    }
}
