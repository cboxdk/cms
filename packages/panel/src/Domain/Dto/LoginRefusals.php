<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\LoginRefusal;

/**
 * The refusal of the login just posted (PRD 5.16), under the field of the form it is about, or under
 * form for the login as a whole; null where there is none.
 */
#[Internal]
final readonly class LoginRefusals
{
    public function __construct(
        public ?LoginRefusal $email,
        public ?LoginRefusal $form,
        public ?LoginRefusal $password,
    ) {}
}
