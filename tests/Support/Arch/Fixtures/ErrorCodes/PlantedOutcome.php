<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes;

/**
 * An enum whose name does not end in ErrorCode, so its cases are no codes.
 */
enum PlantedOutcome: string
{
    case Done = 'planted_done';
}
