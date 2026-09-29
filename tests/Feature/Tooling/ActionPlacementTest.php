<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Tests\Support\Arch\ActionPlacement;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Fixtures\Actions\PlacedQueryAction;
use Examples\Unit\Pipeline\FindNoteTitleAction;

/*
 * The rule behind tests/Arch/ActionsTest.php: an action outside an Actions namespace is reported
 * with its file and line, and a class in Actions that is no action, or an action inside Actions,
 * is not.
 */

function placedType(string $class, string $path): DeclaredType
{
    $position = strrpos($class, '\\');

    return new DeclaredType(substr($class, 0, (int) $position), substr($class, (int) $position + 1), 'class', Codebase::root().'/'.$path, 7);
}

it('reports a write action and a query action outside an Actions namespace', function (): void {
    expect(ActionPlacement::violations([
        placedType(RenameProbeAction::class, 'packages/core/tests/Pipeline/Probe/RenameProbeAction.php'),
        placedType(FindNoteTitleAction::class, 'examples/Unit/Pipeline/FindNoteTitleAction.php'),
    ]))->toBe([
        RenameProbeAction::class.' (packages/core/tests/Pipeline/Probe/RenameProbeAction.php:7) implements WriteAction outside an Actions namespace.',
        FindNoteTitleAction::class.' (examples/Unit/Pipeline/FindNoteTitleAction.php:7) implements QueryAction outside an Actions namespace.',
    ]);
});

it('leaves classes that are no action, and types it cannot load, alone', function (): void {
    expect(ActionPlacement::violations([
        placedType(CommandPipeline::class, 'packages/core/src/Pipeline/Actions/CommandPipeline.php'),
        placedType('Cbox\\Cms\\Core\\Nowhere\\Missing', 'packages/core/src/Nowhere/Missing.php'),
    ]))->toBe([]);
});

it('accepts an action inside an Actions namespace', function (): void {
    expect(ActionPlacement::violations([
        placedType(PlacedQueryAction::class, 'tests/Support/Arch/Fixtures/Actions/PlacedQueryAction.php'),
    ]))->toBe([]);
});
