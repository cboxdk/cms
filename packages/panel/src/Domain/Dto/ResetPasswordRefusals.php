<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\ResetFormRefusal;
use Cbox\Cms\Panel\Domain\ResetPasswordRefusal;

/**
 * The refusal of the password reset just posted (PRD 5.16): under password when it is about the
 * password, under form otherwise; null where there is none.
 */
#[Internal]
final readonly class ResetPasswordRefusals
{
    public function __construct(
        public ?ResetFormRefusal $form,
        public ?ResetPasswordRefusal $password,
    ) {}
}
