<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackAuthorize;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackTransform;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every CommandHooks does, run against RegistryCommandHooks and FakeCommandHooks, so the fake
 * the pipeline's action tests use cannot drift from the registry the application reads
 * (GUARDRAILS 9).
 */
trait CommandHooksBehaviour
{
    /**
     * The implementation under test, knowing exactly the given hooks: each for the name and
     * version of a command, of a package, with its phase, priority and budget.
     *
     * @param  list<array{CommandName, int, BoundHook}>  $hooks
     */
    abstract protected function commandHooks(array $hooks): CommandHooks;

    #[Test]
    public function it_gives_every_hook_of_the_command_s_name_and_version_as_declared(): void
    {
        $authorize = new BoundHook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::noObjection()), 'acme/a', Phase::Authorize, 3, 7);
        $transform = new BoundHook(new CallbackTransform(static fn (): FieldChanges => FieldChanges::none()), 'acme/b', Phase::Transform, -1, 20);
        $validate = new BoundHook(new CallbackValidate(static fn (): HookErrors => HookErrors::none()), 'acme/c', Phase::Validate, 0, 1);
        $publish = new CommandName('note.publish');

        $hooks = $this->commandHooks([
            [$publish, 1, $authorize],
            [$publish, 1, $transform],
            [$publish, 2, $validate],
            [new CommandName('note.archive'), 1, $validate],
        ]);

        $found = $hooks->for($publish, 1);
        usort($found, BoundHook::order(...));

        Assert::assertCount(2, $found);
        Assert::assertSame([$transform->hook, $authorize->hook], array_map(static fn (BoundHook $hook): object => $hook->hook, $found));
        Assert::assertSame(['acme/b', 'acme/a'], array_map(static fn (BoundHook $hook): string => $hook->package, $found));
        Assert::assertSame([Phase::Transform, Phase::Authorize], array_map(static fn (BoundHook $hook): Phase => $hook->phase, $found));
        Assert::assertSame([-1, 3], array_map(static fn (BoundHook $hook): int => $hook->priority, $found));
        Assert::assertSame([20, 7], array_map(static fn (BoundHook $hook): int => $hook->budgetMs, $found));

        $second = $hooks->for($publish, 2);
        Assert::assertCount(1, $second);
        Assert::assertSame($validate->hook, $second[0]->hook);
    }

    #[Test]
    public function it_gives_no_hook_for_a_command_or_version_without_hooks(): void
    {
        $hooks = $this->commandHooks([
            [new CommandName('note.publish'), 1, new BoundHook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::noObjection()), 'acme/a', Phase::Authorize, 0, 1)],
        ]);

        Assert::assertSame([], $hooks->for(new CommandName('note.publish'), 3));
        Assert::assertSame([], $hooks->for(new CommandName('note.archive'), 1));
    }
}
