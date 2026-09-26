<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\InvalidDoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Override;

/**
 * The checks of cms:doctor as a test gives them, with the options each run asked for kept in
 * $asked. It refuses the lists OrderedDoctorChecks refuses, so a test cannot give the doctor a
 * list the application could not build; DoctorChecksBehaviour holds the two together.
 */
final class FakeDoctorChecks implements DoctorChecks
{
    /** @var list<DoctorRunOptions> */
    public array $asked = [];

    /**
     * @param  list<DoctorCheck>  $runtime
     * @param  list<DoctorCheck>  $dev
     *
     * @throws InvalidDoctorCheck
     */
    public function __construct(
        private readonly array $runtime = [],
        private readonly array $dev = [],
    ) {
        $listed = [];

        foreach ([...$runtime, ...$dev] as $check) {
            if (in_array($check->id()->value, $listed, true)) {
                throw InvalidDoctorCheck::duplicate($check->id());
            }

            foreach ($check->requires() as $required) {
                if (! in_array($required->value, $listed, true)) {
                    throw InvalidDoctorCheck::requirement($check->id(), $required);
                }
            }

            $listed[] = $check->id()->value;
        }
    }

    #[Override]
    public function for(DoctorRunOptions $options): array
    {
        $this->asked[] = $options;

        return $options->dev ? [...$this->runtime, ...$this->dev] : $this->runtime;
    }
}
