<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tooling\Check\Domain\CheckListener;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\StepResult;

final class RecordingListener implements CheckListener
{
    /** @var list<string> */
    public array $events = [];

    public function gateStarted(Gate $gate): void
    {
        $this->events[] = "gate {$gate->number}";
    }

    public function stepFinished(Gate $gate, StepResult $result): void
    {
        $this->events[] = "{$result->step} {$result->status->value}";
    }
}
