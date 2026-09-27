<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use InvalidArgumentException;
use Throwable;

/**
 * A value under `cbox-cms.doctor` is invalid, or a check it names cannot be used. cms:doctor reports it
 * as the failing check `doctor.config` instead of stopping.
 */
#[Internal]
final class InvalidDoctorConfig extends InvalidArgumentException
{
    public static function value(string $key, string $expected, string $given): self
    {
        return new self(sprintf('The setting cbox-cms.doctor.%s must be %s; it is %s.', $key, $expected, $given));
    }

    /**
     * A class in cbox-cms.doctor.checks or cbox-cms.doctor.dev_checks that the container cannot build, or
     * whose id() or requires() throws.
     */
    public static function check(string $key, string $class, Throwable $thrown): self
    {
        return new self(sprintf(
            'The check %s in cbox-cms.doctor.%s cannot be used: %s: %s',
            $class,
            $key,
            $thrown::class,
            $thrown->getMessage(),
        ), previous: $thrown);
    }

    /**
     * The checks of cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks do not fit into the list after the
     * core's checks: an id repeats, or a check requires one that does not run before it.
     */
    public static function order(InvalidDoctorCheck $invalid): self
    {
        return new self(sprintf(
            'The checks in cbox-cms.doctor.checks and cbox-cms.doctor.dev_checks cannot run after the core\'s checks: %s',
            $invalid->getMessage(),
        ), previous: $invalid);
    }
}
