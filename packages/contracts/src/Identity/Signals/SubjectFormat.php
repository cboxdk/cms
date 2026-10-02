<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The format of a subject identifier, as RFC 9493 registers them. The core admits only IssSub, the
 * issuer and the subject together, because an IdP identity is (connection, issuer, subject) and an
 * email address or a phone number is never the key to an actor (PRD 5.16).
 */
#[Experimental]
enum SubjectFormat: string
{
    case IssSub = 'iss_sub';
    case Email = 'email';
    case PhoneNumber = 'phone_number';
    case Opaque = 'opaque';
    case Account = 'account';
    case Did = 'did';
    case Uri = 'uri';
    case Aliases = 'aliases';
}
