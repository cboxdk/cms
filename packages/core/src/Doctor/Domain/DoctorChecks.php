<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;

/**
 * The checks of cms:doctor in the order they run: the runtime checks, and the development checks
 * that --dev adds after them.
 *
 * Every id is unique in a run, and a check requires only checks that come before it, so the
 * doctor knows each requirement's result when it reaches the check.
 */
#[Internal]
interface DoctorChecks
{
    /**
     * The checks of one run, in order: the runtime checks, and with $options->dev the development
     * checks after them.
     *
     * @return list<DoctorCheck>
     */
    public function for(DoctorRunOptions $options): array;
}
