<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes;

/**
 * Inherits PlantedFailure's codes, which count only where they are declared, and declares one of
 * its own.
 */
final class PlantedSubFailure extends PlantedFailure
{
    public const string CODE_SUB = 'planted_sub';
}
