<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The tokens a field's `default` may be instead of a constant (gap G2): `today` for a `date`
 * field and `now` for a `datetime` field, which the form resolves from the clock when it opens.
 * The generators never resolve them: a default is form only.
 */
#[Internal]
enum DefaultToken: string
{
    case Today = 'today';

    case Now = 'now';
}
