<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\RegistryName;

/*
 * The registries cms:build writes (PRD 13.2). Only registries with an entry type and a source are
 * cases; subscribers, slots and schema contributions come with the blocks that bring them.
 */

it('has exactly the actions, commands and hooks registries, in that order', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->name, RegistryName::cases()))->toBe(['Actions', 'Commands', 'Hooks'])
        ->and(array_map(static fn (RegistryName $name): string => $name->value, RegistryName::cases()))->toBe(['actions', 'commands', 'hooks']);
});

it('names one PHP file per registry', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->fileName(), RegistryName::cases()))->toBe(['actions.php', 'commands.php', 'hooks.php']);
});
