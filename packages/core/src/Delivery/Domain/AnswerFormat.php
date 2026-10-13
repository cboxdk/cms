<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The form of an answer's body (PRD 8.8, 8.9): the record of what the path shows with its meta,
 * a problem details document (RFC 9457), the explanation of the resolution for an authorized
 * actor, which holds either or neither, or a redirect (PRD 5.8), which has no body and sends its
 * URL in Location.
 */
#[Internal]
enum AnswerFormat: string
{
    case Record = 'record';
    case Problem = 'problem';
    case Explanation = 'explanation';
    case Redirect = 'redirect';
}
