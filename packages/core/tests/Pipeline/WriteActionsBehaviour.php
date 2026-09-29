<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every WriteActions does, run against RegistryWriteActions and FakeWriteActions, so the fake
 * the pipeline's action tests use cannot drift from the registry the application reads
 * (GUARDRAILS 9).
 */
trait WriteActionsBehaviour
{
    /**
     * The implementation under test, knowing only the probe command, probe.rename version 1, handled
     * by the given action.
     */
    abstract protected function writeActions(RenameProbeAction $action): WriteActions;

    #[Test]
    public function it_gives_the_command_s_action_with_the_command_s_name_and_version(): void
    {
        $action = new RenameProbeAction(new ProbeShelf);
        $binding = $this->writeActions($action)->for(new PipelineWorld()->command());

        Assert::assertInstanceOf(ActionBinding::class, $binding);
        Assert::assertTrue($binding->command->equals(new CommandName('probe.rename')));
        Assert::assertSame(1, $binding->version);
        Assert::assertSame($action, $binding->action);
    }

    #[Test]
    public function it_refuses_a_command_no_action_handles(): void
    {
        $stray = new readonly class implements Command {};

        try {
            $this->writeActions(new RenameProbeAction(new ProbeShelf))->for($stray);
            Assert::fail('A command no action handles is refused.');
        } catch (UnknownCommand $unknown) {
            Assert::assertStringContainsString('No write action handles the command '.$stray::class, $unknown->getMessage());
        }
    }
}
