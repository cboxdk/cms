<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Override;

/**
 * The checks of cms:doctor as two lists, the runtime checks and the development checks, in the
 * order they run.
 *
 * The lists are checked when they are made: every id is unique, and a check requires only checks
 * listed before it, so the doctor knows each requirement's result when it reaches the check. The
 * dev checks may require runtime checks; a runtime check may not require a dev check.
 */
#[Internal]
final readonly class OrderedDoctorChecks implements DoctorChecks
{
    /**
     * @param  list<DoctorCheck>  $runtime
     * @param  list<DoctorCheck>  $dev
     *
     * @throws InvalidDoctorCheck when an id repeats or a check requires one that is not before it
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
     * These checks with more after them: $runtime after the runtime checks and $dev after the dev
     * checks, checked by the same rules, so an added check may require any check that runs before
     * it, and may not repeat an id.
     *
     * @param  list<DoctorCheck>  $runtime
     * @param  list<DoctorCheck>  $dev
     *
     * @throws InvalidDoctorCheck when an id repeats or a check requires one that is not before it
     */
    public function with(array $runtime, array $dev): self
    {
        return new self([...$this->runtime, ...$runtime], [...$this->dev, ...$dev]);
    }

    #[Override]
    public function for(DoctorRunOptions $options): array
    {
        return $options->dev ? [...$this->runtime, ...$this->dev] : $this->runtime;
    }
}
