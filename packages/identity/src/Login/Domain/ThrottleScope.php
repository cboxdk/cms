<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the login throttle counts attempts of (PRD 5.16, GUARDRAILS 6): the login identifier a form
 * took, so one account is not guessed at from many addresses, and the client's IP address, so one
 * address does not guess at many accounts. The value is the attribute `cms.limit` of the counter
 * `cms.login.rate_limited`.
 */
#[Internal]
enum ThrottleScope: string
{
    case Identifier = 'identifier';

    case Ip = 'ip';
}
