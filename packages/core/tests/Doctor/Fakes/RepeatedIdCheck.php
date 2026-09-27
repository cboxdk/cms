<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * A check that takes the id of the core's php.version, which the doctor refuses.
 */
final readonly class RepeatedIdCheck extends FixedDoctorCheck
{
    public function __construct()
    {
        parent::__construct('php.version', true, []);
    }
}
