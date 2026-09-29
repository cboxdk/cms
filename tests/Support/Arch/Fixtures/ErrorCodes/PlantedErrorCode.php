<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes;

/**
 * An enum of codes, which ErrorCodeScan reads by its name ending in ErrorCode. One case is no code.
 */
enum PlantedErrorCode: string
{
    case Enum = 'planted_enum';
    case Broken = 'Planted-Broken';
}
