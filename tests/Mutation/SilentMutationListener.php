<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Mutation;

use Cbox\Cms\Tooling\Check\Domain\CheckListener;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\StepResult;

final class SilentMutationListener implements CheckListener
{
    public function gateStarted(Gate $gate): void {}

    public function stepFinished(Gate $gate, StepResult $result): void {}
}
