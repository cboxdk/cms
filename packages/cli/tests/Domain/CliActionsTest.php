<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Domain;

use Cbox\Cms\Cli\Domain\CliActions;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/*
 * The writes the CLI surface runs (GUARDRAILS 2.1): the write actions of the compiled registry
 * that list Surface::Cli, by name and version, and their signatures in a stable order.
 */

/**
 * @param  list<Surface>  $surfaces
 */
function cliEntry(string $name, array $surfaces, int $version = 1, ActionKind $kind = ActionKind::Write): ActionEntry
{
    $class = 'Acme\\Notes\\'.str_replace('.', '', ucwords($name, '.')).'V'.$version;

    return new ActionEntry($class.'Action', 'acme/notes', $kind, new CommandName($name), $version, $class, $surfaces);
}

it('finds each version of a write the registry exposes on the CLI, and nothing else', function (): void {
    $actions = new CliActions(new CompiledRegistry([], [], [
        cliEntry('note.save', [Surface::Rest, Surface::Cli], 2),
        cliEntry('note.save', [Surface::Cli]),
        cliEntry('note.share', [Surface::Rest, Surface::Inertia]),
        cliEntry('note.find', [Surface::Rest, Surface::Cli], kind: ActionKind::Query),
    ]));

    expect($actions->find(new CommandName('note.save'), 1)?->class)->toBe('Acme\\Notes\\NoteSaveV1Action')
        ->and($actions->find(new CommandName('note.save'), 2)?->class)->toBe('Acme\\Notes\\NoteSaveV2Action')
        ->and($actions->find(new CommandName('note.save'), 3))->toBeNull()
        ->and($actions->find(new CommandName('note.share'), 1))->toBeNull()
        ->and($actions->find(new CommandName('note.find'), 1))->toBeNull();
});

it('lists the signatures it exposes sorted by name and version', function (): void {
    $actions = new CliActions(new CompiledRegistry([], [], [
        cliEntry('note.save', [Surface::Cli], 10),
        cliEntry('note.save', [Surface::Cli], 2),
        cliEntry('entry.create', [Surface::Cli]),
        cliEntry('note.save', [Surface::Cli]),
        cliEntry('note.share', [Surface::Rest]),
    ]));

    expect($actions->signatures())->toBe(['entry.create 1', 'note.save 1', 'note.save 2', 'note.save 10'])
        ->and(new CliActions(new CompiledRegistry([], []))->signatures())->toBe([]);
});
