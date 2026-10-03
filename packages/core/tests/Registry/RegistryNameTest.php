<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\RegistryName;

/*
 * The registries cms:build writes (PRD 13.2). Only registries with an entry type and a source are
 * cases; the panel's points (PRD 13.4) are one, read from #[PanelPoint], and the installed addons
 * another, read from their manifests (PRD 13.1).
 */

it('has exactly the actions, addons, commands, hooks, panel, REST routes, schema and subscribers registries, in that order', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->name, RegistryName::cases()))->toBe(['Actions', 'Addons', 'Commands', 'Hooks', 'Panel', 'Rest', 'Schema', 'Subscribers'])
        ->and(array_map(static fn (RegistryName $name): string => $name->value, RegistryName::cases()))->toBe(['actions', 'addons', 'commands', 'hooks', 'panel', 'rest', 'schema', 'subscribers']);
});

it('names one PHP file per registry', function (): void {
    expect(array_map(static fn (RegistryName $name): string => $name->fileName(), RegistryName::cases()))->toBe(['actions.php', 'addons.php', 'commands.php', 'hooks.php', 'panel.php', 'rest.php', 'schema.php', 'subscribers.php']);
});
