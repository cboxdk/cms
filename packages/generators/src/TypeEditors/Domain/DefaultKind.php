<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\TypeEditors\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The two forms of the default document a field of the editor descriptor carries (gap G2): a
 * constant, `{"kind":"constant","value":<value>}`, or a token the form resolves from the clock,
 * `{"kind":"token","token":"today"}` or `"now"`. The values are the `kind` the document names,
 * which the panel's form reads.
 */
#[Internal]
enum DefaultKind: string
{
    case Constant = 'constant';

    case Token = 'token';
}
