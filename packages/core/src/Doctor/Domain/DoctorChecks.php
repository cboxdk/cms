<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;

/**
 * The checks of cms:doctor in the order they run: the runtime checks, and the development checks
 * that --dev adds after them.
 *
 * Every id is unique, and a check requires only checks listed before it, so the doctor knows each
 * requirement's result when it reaches the check. The dev checks may require runtime checks.
 */
#[Internal]
final readonly class DoctorChecks
{
    /**
     * @param  list<DoctorCheck>  $runtime
     * @param  list<DoctorCheck>  $dev
     */
    public function __construct(
        public array $runtime,
        public array $dev,
    ) {
        $seen = [];

        foreach ([...$runtime, ...$dev] as $check) {
            $id = $check->id();

            if (isset($seen[$id->value])) {
                throw InvalidDoctorCheck::duplicate($id);
            }

            foreach ($check->requires() as $required) {
                if (! isset($seen[$required->value])) {
                    throw InvalidDoctorCheck::requirement($id, $required);
                }
            }

            $seen[$id->value] = true;
        }
    }

    /**
     * The checks of one run: the runtime checks, and with $dev the development checks after them.
     *
     * @return list<DoctorCheck>
     */
    public function for(bool $dev): array
    {
        return $dev ? [...$this->runtime, ...$this->dev] : $this->runtime;
    }
}
