<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\Password;

/**
 * A local staff member to register (PRD 5.16): the email address, which is the profile's contact
 * address and, in lower case, the login of the local account, the display name of the profile,
 * and the first password.
 */
#[Internal]
final readonly class StaffRegistration
{
    public function __construct(
        public EmailAddress $email,
        public DisplayName $name,
        public Password $password,
    ) {}
}
