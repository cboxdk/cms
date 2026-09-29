<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe;

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;

/**
 * One step of the probe.
 */
final readonly class ProbeStep
{
    /**
     * @param  list<numeric-string>|Omitted|null  $amounts  The amounts.
     * @param  Step  $step  The step.
     */
    public function __construct(
        public array|Omitted|null $amounts,
        public Step $step,
    ) {}
}
