<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Surface;
use Examples\Unit\Pipeline\FindNoteTitleAction;
use Examples\Unit\Pipeline\SaveNote;
use Examples\Unit\Pipeline\SaveNoteAction;

// What the build reads from the declarations: the command's name and version from #[Command],
// and each action's surfaces from #[Action], sorted in the order of the Surface enum.

it('declares the command and the surfaces of each action', function (): void {
    $command = new ReflectionClass(SaveNote::class)->getAttributes(Command::class)[0]->newInstance();
    $write = new ReflectionClass(SaveNoteAction::class)->getAttributes(Action::class)[0]->newInstance();
    $query = new ReflectionClass(FindNoteTitleAction::class)->getAttributes(Action::class)[0]->newInstance();

    expect($command->name()->value)->toBe('note.save')
        ->and($command->version)->toBe(1)
        ->and($write->surfaces)->toBe([Surface::Rest, Surface::Mcp])
        ->and($write->exposes(Surface::Cli))->toBeFalse()
        ->and($query->surfaces)->toBe([Surface::Rest, Surface::Inertia]);
});
