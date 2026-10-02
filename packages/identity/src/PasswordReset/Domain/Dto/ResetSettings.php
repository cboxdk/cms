<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetPage;
use InvalidArgumentException;

/**
 * The settings of the password reset (PRD 5.16), from `cbox-cms.identity.password_reset`: how many
 * minutes a link works, MIN_MINUTES to MAX_MINUTES, and the page the link points at.
 */
#[Internal]
final readonly class ResetSettings
{
    public const int DEFAULT_MINUTES = 60;

    public const int MIN_MINUTES = 5;

    public const int MAX_MINUTES = 1440;

    /**
     * @throws InvalidArgumentException when the minutes are out of their range
     */
    public function __construct(
        public int $tokenMinutes,
        public ResetPage $page,
    ) {
        if ($tokenMinutes < self::MIN_MINUTES || $tokenMinutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException(sprintf('A password reset link works %d to %d minutes.', self::MIN_MINUTES, self::MAX_MINUTES));
        }
    }
}
