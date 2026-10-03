<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\ForgotPasswordRefusal;

/**
 * The refusal of the request for a password reset link just posted (PRD 5.16), under the field it
 * is about; null where there is none.
 */
#[Internal]
final readonly class ForgotPasswordRefusals
{
    public function __construct(
        public ?ForgotPasswordRefusal $email,
    ) {}
}
