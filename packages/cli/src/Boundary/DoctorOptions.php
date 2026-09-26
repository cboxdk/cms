<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;

/**
 * Reads the options of cms:doctor that choose what it checks. --dev is a flag: Symfony gives true
 * when it is present and false when it is not; anything but true leaves the development checks out.
 */
#[Internal]
final readonly class DoctorOptions
{
    public static function parse(mixed $dev): DoctorRunOptions
    {
        return new DoctorRunOptions(dev: $dev === true);
    }
}
