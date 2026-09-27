<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

/**
 * A check with a constructor argument the container cannot resolve without a contextual binding.
 */
final readonly class UnbuildableCheck extends FixedDoctorCheck
{
    public function __construct(public string $directory)
    {
        parent::__construct('addon.directory', true, []);
    }
}
