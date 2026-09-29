<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\OverrunKind;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every HookOverruns does, run against LoggedHookOverruns and FakeHookOverruns (GUARDRAILS
 * 9): each overrun is recorded once, in order, with everything it says.
 */
trait HookOverrunsBehaviour
{
    abstract protected function overruns(): HookOverruns;

    /**
     * What the implementation under test recorded, read back as overruns.
     *
     * @return list<HookOverrun>
     */
    abstract protected function recorded(HookOverruns $overruns): array;

    #[Test]
    public function it_records_each_overrun_once_in_order_with_everything_it_says(): void
    {
        $overruns = $this->overruns();
        $hook = new HookOverrun(new CommandName('note.publish'), 2, CallbackValidate::class, 'acme/slow', Phase::Validate, OverrunKind::Hook, 5, 5_000_001, 9_000_000);
        $command = new HookOverrun(new CommandName('note.archive'), 1, CallbackValidate::class, 'acme/last', Phase::Authorize, OverrunKind::Command, 20, 1, 100_000_001);

        $overruns->record($hook);
        $overruns->record($command);

        Assert::assertEquals([$hook, $command], $this->recorded($overruns));
    }
}
