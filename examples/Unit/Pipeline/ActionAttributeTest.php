<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Query;
use Cbox\Cms\Contracts\Attributes\Surface;
use Examples\Unit\Pipeline\FindNoteTitle;
use Examples\Unit\Pipeline\FindNoteTitleAction;
use Examples\Unit\Pipeline\SaveNote;
use Examples\Unit\Pipeline\SaveNoteAction;

// What the build reads from the declarations: the name and version of the command from #[Command]
// and of the query from #[Query], and each action's class it handles and its surfaces from
// #[Action], sorted in the order of the Surface enum.

it('declares what each action handles and the surfaces it is exposed on', function (): void {
    $command = new ReflectionClass(SaveNote::class)->getAttributes(Command::class)[0]->newInstance();
    $query = new ReflectionClass(FindNoteTitle::class)->getAttributes(Query::class)[0]->newInstance();
    $write = new ReflectionClass(SaveNoteAction::class)->getAttributes(Action::class)[0]->newInstance();
    $read = new ReflectionClass(FindNoteTitleAction::class)->getAttributes(Action::class)[0]->newInstance();

    expect($command->name()->value)->toBe('note.save')
        ->and($command->version)->toBe(1)
        ->and($query->name()->value)->toBe('note.find_title')
        ->and($query->version)->toBe(1)
        ->and($write->handles)->toBe(SaveNote::class)
        ->and($write->surfaces)->toBe([Surface::Rest, Surface::Mcp])
        ->and($write->exposes(Surface::Cli))->toBeFalse()
        ->and($read->handles)->toBe(FindNoteTitle::class)
        ->and($read->surfaces)->toBe([Surface::Rest, Surface::Inertia]);
});
