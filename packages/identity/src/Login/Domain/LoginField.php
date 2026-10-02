<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A field of a local login form (PRD 5.16): the identifier, the email address a person types, and
 * the password. A refusal that is about one field names it, so a form shows the error there.
 */
#[Internal]
enum LoginField: string
{
    case Identifier = 'identifier';

    case Password = 'password';
}
