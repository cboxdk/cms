<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Adapter\RegistryCommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\HookBudget;
use Cbox\Cms\Core\Pipeline\Domain\InvalidHook;
use Cbox\Cms\Core\Pipeline\Domain\OverrunKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackAuthorize;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackTransform;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Illuminate\Container\Container;
use stdClass;

/*
 * A hook as the pipeline runs it, its budget and its overrun, beside the pipeline's action tests.
 */

function boundView(): PlanView
{
    return new PlanView(new CommandName('probe.rename'), 1, new AnonymousPrincipal, ClassificationAccess::Public);
}

function boundValidate(int $budgetMs = 20, int $priority = 0, string $package = 'acme/probe'): BoundHook
{
    return new BoundHook(new CallbackValidate(static fn (): HookErrors => HookErrors::none()), $package, Phase::Validate, $priority, $budgetMs);
}

it('refuses a hook that does not implement the interface of its phase', function (Phase $phase): void {
    $hook = $phase === Phase::Validate
        ? new CallbackAuthorize(static fn (): HookDecision => HookDecision::noObjection())
        : new CallbackValidate(static fn (): HookErrors => HookErrors::none());

    expect(static fn (): BoundHook => new BoundHook($hook, 'acme/probe', $phase, 0, 1))
        ->toThrow(InvalidHook::class, sprintf('The hook %s is registered for the %s phase and does not implement %s. Run cms:build again after changing a hook.', $hook::class, $phase->value, $phase->hookInterface()));
})->with([Phase::Authorize, Phase::Transform, Phase::Validate]);

it('refuses a budget outside 1 to 20 ms and takes both ends', function (): void {
    expect(boundValidate(1)->budgetNanoseconds())->toBe(1_000_000)
        ->and(boundValidate(20)->budgetNanoseconds())->toBe(20_000_000)
        ->and(static fn (): BoundHook => boundValidate(0))->toThrow(InvalidHook::class, 'The hook '.CallbackValidate::class.' has a budget of 0 ms. It must be between 1 and 20 ms.')
        ->and(static fn (): BoundHook => boundValidate(21))->toThrow(InvalidHook::class, 'has a budget of 21 ms.');
});

it('runs only the method of its phase', function (): void {
    $authorize = new BoundHook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::deny('No.')), 'acme/probe', Phase::Authorize, 0, 1);
    $transform = new BoundHook(new CallbackTransform(static fn (): FieldChanges => FieldChanges::none()), 'acme/probe', Phase::Transform, 0, 1);
    $validate = boundValidate();

    expect($authorize->authorize(boundView())->reason)->toBe('No.')
        ->and($transform->transform(boundView())->isEmpty())->toBeTrue()
        ->and($validate->validate(boundView())->isEmpty())->toBeTrue()
        ->and($authorize->class)->toBe(CallbackAuthorize::class)
        ->and(static fn (): FieldChanges => $authorize->transform(boundView()))->toThrow(InvalidHook::class, 'registered for the transform phase')
        ->and(static fn (): HookErrors => $authorize->validate(boundView()))->toThrow(InvalidHook::class, 'registered for the validate phase')
        ->and(static fn (): HookDecision => $validate->authorize(boundView()))->toThrow(InvalidHook::class, 'registered for the authorize phase');
});

it('orders hooks by priority, then package, then class', function (): void {
    $low = boundValidate(priority: -1, package: 'zeta/z');
    $a = boundValidate(package: 'acme/a');
    $b = boundValidate(package: 'acme/b');
    $authorize = new BoundHook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::noObjection()), 'acme/b', Phase::Authorize, 0, 1);
    $hooks = [$b, $authorize, $a, $low];
    usort($hooks, BoundHook::order(...));

    expect($hooks)->toBe([$low, $a, $authorize, $b]);
});

it('charges a hook its time and stops at the first budget it goes over', function (): void {
    $command = new CommandName('probe.rename');
    $hook = boundValidate(5);
    $charged = new HookBudget(95_000_000)->charge($hook, 5_000_000, $command, 1);

    expect($charged)->toBeInstanceOf(HookBudget::class)
        ->and($charged instanceof HookBudget ? $charged->spentNanoseconds : null)->toBe(100_000_000)
        ->and(new HookBudget()->charge($hook, -3, $command, 1))->toEqual(new HookBudget(0));

    $own = new HookBudget(99_000_000)->charge($hook, 5_000_001, $command, 1);

    expect($own)->toBeInstanceOf(HookOverrun::class)
        ->and($own instanceof HookOverrun ? $own->kind : null)->toBe(OverrunKind::Hook)
        ->and($own instanceof HookOverrun ? $own->spentNanoseconds : null)->toBe(104_000_001)
        ->and(new HookBudget(95_000_001)->charge($hook, 5_000_000, $command, 1))->toEqual(new HookOverrun($command, 1, CallbackValidate::class, 'acme/probe', Phase::Validate, OverrunKind::Command, 5, 5_000_000, 100_000_001))
        ->and(HookBudget::COMMAND_NANOSECONDS)->toBe(100_000_000);
});

it('describes an overrun in milliseconds', function (): void {
    $command = new CommandName('note.publish');

    expect(new HookOverrun($command, 2, CallbackValidate::class, 'acme/slow', Phase::Transform, OverrunKind::Hook, 5, 5_123_456, 7_000_000)->describe())
        ->toBe('The transform hook '.CallbackValidate::class.' of acme/slow took 5.123 ms, over its budget of 5 ms.')
        ->and(new HookOverrun($command, 2, CallbackValidate::class, 'acme/last', Phase::Authorize, OverrunKind::Command, 20, 1_500_000, 100_250_000)->describe())
        ->toBe('The hooks of note.publish version 2 took 100.250 ms together, over the budget of 100 ms all hooks of a command have; the last was the authorize hook '.CallbackValidate::class.' of acme/last, which took 1.500 ms.')
        ->and(new HookOverrun($command, 2, CallbackValidate::class, 'acme/stuck', Phase::Validate, OverrunKind::Hook, 5, 1_234_567_890, 1_234_567_890)->describe())
        ->toBe('The validate hook '.CallbackValidate::class.' of acme/stuck took 1234.568 ms, over its budget of 5 ms.');
});

it('refuses a registered hook that the container builds as no hook', function (): void {
    $container = new Container;
    $container->instance('Acme\Hooks\NotAHook', new stdClass);
    $hooks = new RegistryCommandHooks(new CompiledRegistry([], [
        new HookEntry('Acme\Hooks\NotAHook', 'acme/hooks', new CommandName('probe.rename'), 1, RenameProbe::class, Phase::Transform, 0, 1),
    ]), $container);

    expect(static fn (): array => $hooks->for(new CommandName('probe.rename'), 1))
        ->toThrow(InvalidHook::class, 'The hook Acme\Hooks\NotAHook is registered for the transform phase and does not implement Cbox\Cms\Contracts\Hooks\TransformHook.');
});
