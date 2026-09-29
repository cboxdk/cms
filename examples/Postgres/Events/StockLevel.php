<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

/**
 * How full a warehouse is: a closed set, so an event may carry it.
 */
enum StockLevel: string
{
    case Empty = 'empty';
    case Low = 'low';
    case Full = 'full';
}
