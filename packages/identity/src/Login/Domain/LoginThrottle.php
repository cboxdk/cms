<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;

/**
 * The rate limit of local logins (PRD 5.16, GUARDRAILS 6), per login identifier and per IP address,
 * each within the limit LoginThrottleSettings gives its scope.
 *
 * Every attempt is counted first, under both keys at once, so attempts that run at the same time
 * cannot all pass before any is counted; a key's window starts with its first counted attempt and
 * is not moved by later ones. An attempt that logs in is taken back: the identifier's count is
 * cleared and the IP address's count goes down by one, so a person who logs in starts again and an
 * address shared by many people counts only the attempts that failed.
 */
#[Internal]
interface LoginThrottle
{
    /**
     * Counts an attempt under both keys and returns the first scope, the identifier before the IP
     * address, whose count is now above its limit, or null when the attempt may go on.
     */
    public function hit(LoginThrottleKeys $keys): ?ThrottleScope;

    /**
     * Takes back the attempt of a login that succeeded: clears the identifier's count and lowers
     * the IP address's count by one.
     */
    public function succeeded(LoginThrottleKeys $keys): void;
}
