<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;
use Cbox\Cms\Generators\Scaffold\Domain\IndexModule;

/*
 * The registration module and the ids module cms:make:addon-ui writes and cms:make:panel adds to
 * (PRD 13.4): the entries sorted by id with the imports a check needs; an entry added right after
 * the opening of an empty or a populated registration, with its import after the last import;
 * nothing added twice; and a module laid out otherwise left alone, so the person adds the entry
 * by hand.
 */

function entry(string $id, string $expression, ?string $import = null): RegistrationEntry
{
    return new RegistrationEntry(new ContributionId($id), $expression, $import);
}

it('writes the registration with its entries sorted by id and the imports a check needs', function (): void {
    $module = IndexModule::index(new AddonNamespace('tally'), [
        entry('tally.title-check', 'titleCheckCheck', "import { titleCheckCheck } from './TitleCheckCheck';"),
        entry('tally.badge', "() => import('./Badge')"),
    ]);

    expect($module->path)->toBe('resources/panel/src/index.ts')
        ->and($module->contents)->toBe(
            "// The panel UI of the addon tally: the registration of its contributions, the default export\n"
            ."// of its bundle's entry (PRD 13.4). Each key is a contribution of the manifest that runs code,\n"
            ."// and cms:panel:types writes Contributions from the manifest, so tsc refuses a missing key, an\n"
            ."// extra key and a component or function with other props.\n\n"
            ."import { definePanelAddon } from '@cboxdk/cms-panel/extend';\n\n"
            ."import type { Contributions } from '../generated/contributions';\n"
            ."import { titleCheckCheck } from './TitleCheckCheck';\n\n"
            ."export default definePanelAddon<Contributions>({\n"
            ."  'tally.badge': () => import('./Badge'),\n"
            ."  'tally.title-check': titleCheckCheck,\n"
            ."});\n",
        )
        ->and(IndexModule::index(new AddonNamespace('tally'), [])->contents)->toContain("export default definePanelAddon<Contributions>({});\n");
});

it('adds an entry to an empty and to a populated registration, with its import, and never twice', function (): void {
    $empty = IndexModule::index(new AddonNamespace('tally'), [])->contents;
    $populated = IndexModule::index(new AddonNamespace('tally'), [entry('tally.badge', "() => import('./Badge')")])->contents;
    $check = entry('tally.title-check', 'titleCheckCheck', "import { titleCheckCheck } from './TitleCheckCheck';");

    $fromEmpty = (string) IndexModule::withEntry($empty, $check);
    $fromPopulated = (string) IndexModule::withEntry($populated, $check);

    expect($fromEmpty)->toContain(
        "import type { Contributions } from '../generated/contributions';\nimport { titleCheckCheck } from './TitleCheckCheck';\n",
        "export default definePanelAddon<Contributions>({\n  'tally.title-check': titleCheckCheck,\n});\n",
    )
        ->and($fromPopulated)->toContain("export default definePanelAddon<Contributions>({\n  'tally.title-check': titleCheckCheck,\n  'tally.badge': () => import('./Badge'),\n});\n")
        ->and(substr_count($fromPopulated, 'import { titleCheckCheck }'))->toBe(1)
        ->and(IndexModule::withEntry($fromPopulated, $check))->toBeNull()
        ->and(IndexModule::withEntry("export default definePanelAddon<Contributions>(contributions);\n", $check))->toBeNull()
        ->and(IndexModule::withEntry("export default definePanelAddon<Contributions>({});\n", $check))->toBeNull('an import needs an import to follow');
});

it('writes the ids sorted and each once, and reads them back from a module it wrote', function (): void {
    $module = IndexModule::ids(new AddonNamespace('tally'), ['tally.title-check', 'tally.badge', "tally.it's", 'tally.badge']);

    expect($module->path)->toBe('resources/panel/src/ids.ts')
        ->and($module->contents)->toContain("export const CONTRIBUTIONS: readonly string[] = [\n  'tally.badge',\n  'tally.it\\'s',\n  'tally.title-check',\n];\n")
        ->and(IndexModule::idsOf($module->contents))->toBe(['tally.badge', "tally.it's", 'tally.title-check'])
        ->and(IndexModule::idsOf(IndexModule::ids(new AddonNamespace('tally'), [])->contents))->toBe([])
        ->and(IndexModule::idsOf("export const CONTRIBUTIONS = ids;\n"))->toBeNull();
});
