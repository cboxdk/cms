<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Inertia;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Inertia\Domain\SurfaceParityBroken;

/*
 * The actions the Inertia profile exposes (GUARDRAILS 2.1), and the parity of the panel and REST
 * (decided by Sylvester on 29 September 2026): a registry with an action on Inertia that is not on
 * REST is refused, naming every such action, so the profile serves nothing until REST has it too.
 */

/**
 * @param  list<Surface>  $surfaces
 */
function inertiaEntry(string $name, array $surfaces, int $version = 1, ActionKind $kind = ActionKind::Write): ActionEntry
{
    $class = 'Acme\\Notes\\'.str_replace('.', '', ucwords($name, '.')).'V'.$version;

    return new ActionEntry($class.'Action', 'acme/notes', $kind, new CommandName($name), $version, $class, $surfaces);
}

it('finds each version of a write the registry exposes on Inertia and REST', function (): void {
    $actions = new InertiaActions(new CompiledRegistry([], [], [
        inertiaEntry('note.save', [Surface::Rest, Surface::Inertia]),
        inertiaEntry('note.save', [Surface::Rest, Surface::Inertia], 2),
        inertiaEntry('note.share', [Surface::Rest]),
        inertiaEntry('note.find', [Surface::Rest, Surface::Inertia], kind: ActionKind::Query),
    ]));

    expect($actions->find(new CommandName('note.save'), 1)?->class)->toBe('Acme\\Notes\\NoteSaveV1Action')
        ->and($actions->find(new CommandName('note.save'), 2)?->class)->toBe('Acme\\Notes\\NoteSaveV2Action')
        ->and($actions->find(new CommandName('note.save'), 3))->toBeNull()
        ->and($actions->find(new CommandName('note.share'), 1))->toBeNull()
        ->and($actions->find(new CommandName('note.find'), 1))->toBeNull();
});

it('refuses a registry with a planted action on Inertia and not on REST, naming every such action', function (): void {
    expect(static fn (): InertiaActions => new InertiaActions(new CompiledRegistry([], [], [
        inertiaEntry('note.save', [Surface::Rest, Surface::Inertia]),
        inertiaEntry('note.archive', [Surface::Inertia, Surface::Cli]),
        inertiaEntry('note.find', [Surface::Inertia], kind: ActionKind::Query),
    ])))->toThrow(
        SurfaceParityBroken::class,
        'The Inertia profile exposes Acme\\Notes\\NoteArchiveV1Action (note.archive version 1), Acme\\Notes\\NoteFindV1Action (note.find version 1), but REST does not. Everything the panel can do, REST can do too: add Surface::Rest to #[Action] and run cms:build.',
    );
});

it('accepts an empty registry', function (): void {
    expect(new InertiaActions(CompiledRegistry::empty())->find(new CommandName('note.save'), 1))->toBeNull();
});
