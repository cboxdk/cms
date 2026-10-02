<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a request for a password reset link ended (PRD 5.16): taken, or refused with
 * validation_required for an email left empty. A request that was taken is answered the same
 * whether a link was mailed or not, so the answer never tells whether an account exists.
 */
#[Internal]
final readonly class ResetRequestOutcome
{
    private function __construct(public bool $taken) {}

    public static function taken(): self
    {
        return new self(true);
    }

    /**
     * The email was left empty: validation_required on the field.
     */
    public static function emailMissing(): self
    {
        return new self(false);
    }
}
