<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;

/*
 * Every write action of the compiled registry that a surface exposes has a registered
 * CommandCodec (GUARDRAILS 2.1, 2.2): the codecs composer generate:protocol writes from the
 * kernel's command schemas, which the core registers under CommandCodecs::TAG. A planted write
 * exposed on a surface without a codec is reported; one exposed on no surface, and a codec for
 * each of the kernel's exposed commands, are not.
 */

/**
 * probe.rename, version 1, exposed on the surfaces given.
 *
 * @param  list<Surface>  $surfaces
 */
function plantedWrite(array $surfaces): ActionEntry
{
    return new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, $surfaces);
}

it('has a codec for every write action the compiled registry exposes on a surface', function (): void {
    $actions = app(CompiledRegistry::class)->actions;
    $exposed = array_values(array_filter($actions, static fn (ActionEntry $action): bool => $action->kind === ActionKind::Write && $action->surfaces !== []));

    expect(ExposedCommandCodecs::missing($actions, app(CommandCodecs::class)))->toBe([])
        ->and(array_map(static fn (ActionEntry $action): string => $action->command->value.' v'.$action->commandVersion, $exposed))->toContain(
            'entry.create v1',
            'entry.revise v1',
            'variant.release v1',
            'entry.publish v1',
            'entry.unpublish v1',
            'placement.create v1',
            'placement.set_window v1',
        );
});

it('reports a write action exposed on a surface without a codec, and not one exposed on none, and each kernel command without the kernel\'s codecs', function (): void {
    $actions = [...app(CompiledRegistry::class)->actions, plantedWrite([Surface::Rest, Surface::Mcp]), plantedWrite([])];

    expect(ExposedCommandCodecs::missing($actions, app(CommandCodecs::class)))->toBe(['probe.rename v1 on rest, mcp'])
        ->and(ExposedCommandCodecs::missing(app(CompiledRegistry::class)->actions, new CommandCodecs(ExposedWorld::codec())))->toContain('entry.create v1 on rest, inertia, mcp, cli');
});

it('registers the codec of each of the kernel\'s commands under its name and version', function (string $name): void {
    $codec = app(CommandCodecs::class)->find(new CommandName($name), 1);

    expect($codec?->command->value)->toBe($name)
        ->and($codec?->version)->toBe(1);
})->with(['entry.create', 'entry.revise', 'variant.release', 'entry.publish', 'entry.unpublish', 'placement.create', 'placement.set_window', 'actor.deactivate', 'actor.register', 'actor.activate']);
