<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ErrorEntry;

/**
 * Uses the catalog's case for dry runs by name, as a surface does, and names one that is no case.
 */
final class PlantedSurface
{
    public function dryRun(): ErrorEntry
    {
        return ErrorCode::DryRun->entry();
    }

    public function pattern(): string
    {
        return ErrorCode::PATTERN;
    }
}
