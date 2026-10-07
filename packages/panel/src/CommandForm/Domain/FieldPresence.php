<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Whether a member of a command document must be there and may be null, as the kernel's generated
 * validators say it (PRD 6.1): required, a value that is never null; present, a key that is there
 * and may be null; optional, a key that may be missing or null; omittable, a key that may be
 * missing and is never null. A field input of the generic command form is handed its member's
 * presence, so a replacement of the input shows and checks what the default does.
 */
#[Experimental]
enum FieldPresence: string
{
    case Required = 'required';
    case Present = 'present';
    case Optional = 'optional';
    case Omittable = 'omittable';
}
