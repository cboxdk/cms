<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Domain;

/**
 * What tools/bin/services.php is asked to do: `up` for `composer services:up`, `down` for
 * `composer services:down`.
 */
enum ServicesAction: string
{
    case Up = 'up';
    case Down = 'down';
}
