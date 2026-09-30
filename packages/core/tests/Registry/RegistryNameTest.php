<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\RegistryName;

/*
 * The registries cms:build writes (PRD 13.2). Only registries with an entry type and a source are
 * cases; slots come with the block that brings them.
 */

it('has exactly the actions, commands, hooks, REST routes, schema and subscribers registries, in that order', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->name, RegistryName::cases()))->toBe(['Actions', 'Commands', 'Hooks', 'Rest', 'Schema', 'Subscribers'])
        ->and(array_map(static fn (RegistryName $name): string => $name->value, RegistryName::cases()))->toBe(['actions', 'commands', 'hooks', 'rest', 'schema', 'subscribers']);
});

it('names one PHP file per registry', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->fileName(), RegistryName::cases()))->toBe(['actions.php', 'commands.php', 'hooks.php', 'rest.php', 'schema.php', 'subscribers.php']);
});
