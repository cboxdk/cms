<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Core\Doctor\Domain\InvalidDoctorConfig;
use Illuminate\Contracts\Container\Container;
use Throwable;
use UnexpectedValueException;

/**
 * Builds the checks that an application or addon names in `cbox-cms.doctor.checks` and
 * `cbox-cms.doctor.dev_checks` from the container, so a check gets what it looks at through its
 * constructor, as the contract asks.
 *
 * A check that cannot be used is a configuration problem, not a crash of cms:doctor: when the
 * container cannot build the class, a binding gives something that is not a DoctorCheck, or its id(),
 * blocking() or requires() throws, as for an invalid CheckId, this throws InvalidDoctorConfig, and
 * the doctor reports the failing check doctor.config.
 */
#[Internal]
final readonly class ContainerDoctorChecks
{
    public function __construct(private Container $container) {}

    /**
     * The checks of one setting, in its order.
     *
     * @param  string  $key  the setting below cbox-cms.doctor that names the classes, for the message
     * @param  list<class-string<DoctorCheck>>  $classes
     * @return list<DoctorCheck>
     *
     * @throws InvalidDoctorConfig
     */
    public function build(string $key, array $classes): array
    {
        $checks = [];

        foreach ($classes as $class) {
            try {
                $check = $this->container->make($class);

                if (! $check instanceof DoctorCheck) {
                    throw new UnexpectedValueException(sprintf('The container gives %s, which does not implement %s.', get_debug_type($check), DoctorCheck::class));
                }

                $check->id();
                $check->blocking();
                $check->requires();
            } catch (Throwable $thrown) {
                throw InvalidDoctorConfig::check($key, $class, $thrown);
            }

            $checks[] = $check;
        }

        return $checks;
    }
}
