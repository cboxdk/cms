<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * A value under `cms.doctor` is invalid. cms:doctor reports it as the failing check
 * `doctor.config` instead of stopping.
 */
#[Internal]
final class InvalidDoctorConfig extends InvalidArgumentException
{
    public static function value(string $key, string $expected, string $given): self
    {
        return new self(sprintf('The setting cms.doctor.%s must be %s; it is %s.', $key, $expected, $given));
    }
}
