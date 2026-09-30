<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where an answer of the delivery API came from (PRD 8.12, 9.3): a stored fragment, without a read,
 * or the origin, a read through the query pipeline.
 */
#[Internal]
enum DeliverySource: string
{
    case Fragment = 'fragment';
    case Origin = 'origin';
}
