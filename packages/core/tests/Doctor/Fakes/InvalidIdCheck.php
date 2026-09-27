<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * A check whose id() throws InvalidDoctorCheck, because its id is not lowercase snake_case segments.
 */
final readonly class InvalidIdCheck extends FixedDoctorCheck
{
    public function __construct()
    {
        parent::__construct('Addon Ready', true, []);
    }
}
