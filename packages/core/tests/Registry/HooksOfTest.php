<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;

/*
 * CompiledRegistry::hooksOf(), where the command pipeline and cms:hooks both take the hooks of a
 * command version (PRD 13.2): the hooks of that name and version alone, in the registry's order,
 * which is the order they run.
 */

/**
 * @return list<string>
 */
function hooksOf(string $command, int $version): array
{
    return array_map(
        static fn (HookEntry $hook): string => $hook->class,
        InspectedRegistry::compiled()->hooksOf(new CommandName($command), $version),
    );
}

it('gives the hooks of one version of a command in the order they run', function (): void {
    expect(hooksOf('note.create', 1))->toBe([
        'Acme\Notes\CheckQuota',
        'Acme\Notes\SlugTitle',
        'Acme\Audit\TrimTitle',
        'Acme\Notes\TrimTitle',
        'Acme\Notes\RequireTitle',
    ])
        ->and(hooksOf('note.create', 2))->toBe(['Acme\Notes\RequireBody']);
});

it('gives no hooks for a version, a command or a query that has none', function (): void {
    expect(hooksOf('note.create', 3))->toBe([])
        ->and(hooksOf('note.archive', 1))->toBe([])
        ->and(hooksOf('note.find', 1))->toBe([])
        ->and(CompiledRegistry::empty()->hooksOf(new CommandName('note.create'), 1))->toBe([]);
});
