<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Actions\WritePanelTypes;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PanelTypesRequest;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Generators\Tests\PanelTypes\PanelTypesWorld;

/*
 * cms:panel:types' action called directly with its PanelTypesRequest (GUARDRAILS 9): the
 * registry adapter over a fake registry cache with the world's registry, the kernel's command
 * codecs and the test queries' codecs, and the fake output. It writes the addon's module below its
 * package and removes what else is in the directory, with a contribution to a point whose props
 * class names the SDK's type through HostProps typed on that type, and refuses an addon that is
 * not installed, a registry it cannot read and a command without a codec.
 */

function writePanelTypes(?CompiledRegistry $registry, FakeGeneratedOutput $output, ?string $root = '/srv/addons'): WritePanelTypes
{
    return new WritePanelTypes(PanelTypesWorld::source($registry, $root), $output);
}

it('writes the module of the addon\'s contributions that run code below its package', function (): void {
    $output = new FakeGeneratedOutput;
    $output->put('/srv/addons/acme/cms-tally/resources/panel/generated/stale.ts', "export {};\n");

    $report = writePanelTypes(PanelTypesWorld::registry(), $output)->write(new PanelTypesRequest(new AddonNamespace('tally')));
    $module = (string) $output->contents('/srv/addons/acme/cms-tally/resources/panel/generated/contributions.ts');

    expect($report->written)->toBe(['resources/panel/generated/contributions.ts'])
        ->and($report->removed)->toBe(['resources/panel/generated/stale.ts'])
        ->and($module)->toContain(
            "import type { NoteCardV1, NoteFieldProps } from '@cboxdk/cms-panel/experimental';",
            "readonly 'tally.badge': Lazy<SlotComponent<NoteCardV1, TallyNotesResultV1>>;",
            "readonly 'tally.slug-input': Lazy<Replacement<NoteFieldProps>>;",
            "readonly 'tally.title-check': FormCheck<EntryCreateV1>;",
            "readonly 'entry.create@1': EntryCreateV1;",
            'export interface TallyNotesResultV1 {',
            'export interface EntryCreateV1 {',
        )
        ->and($module)->not->toContain('tally.nav', 'approvals.badge', 'NoteFieldV1')
        ->and(substr_count($module, 'export interface EntryCreateV1 {'))->toBe(1);
});

it('changes nothing on a second run', function (): void {
    $output = new FakeGeneratedOutput;
    $action = writePanelTypes(PanelTypesWorld::registry(), $output);
    $action->write(new PanelTypesRequest(new AddonNamespace('tally')));

    $again = $action->write(new PanelTypesRequest(new AddonNamespace('tally')));

    expect($again->written)->toBe([])
        ->and($again->removed)->toBe([])
        ->and($again->unchanged)->toBe(['resources/panel/generated/contributions.ts']);
});

it('writes NoContributions and NoCommands for an addon that runs no code and issues nothing', function (): void {
    $output = new FakeGeneratedOutput;

    writePanelTypes(PanelTypesWorld::registry(), $output)->write(new PanelTypesRequest(new AddonNamespace('approvals')));

    expect((string) $output->contents('/srv/addons/acme/cms-approvals/resources/panel/generated/contributions.ts'))->toContain(
        "readonly 'approvals.badge': Lazy<SlotComponent<NoteCardV1>>;",
        'export type Issues = NoCommands;',
    );
});

it('refuses what it cannot write the types of, and writes nothing', function (bool $built, ?string $root, string $namespace, GenerateErrorCode $code, string $cause): void {
    $output = new FakeGeneratedOutput;

    $failed = null;

    try {
        writePanelTypes($built ? PanelTypesWorld::registry() : null, $output, $root)->write(new PanelTypesRequest(new AddonNamespace($namespace)));
    } catch (GenerationFailed $refused) {
        $failed = $refused;
    }

    expect($failed)->toBeInstanceOf(GenerationFailed::class);
    assert($failed instanceof GenerationFailed);
    expect($failed->problems[0]->code)->toBe($code)
        ->and($failed->problems[0]->describe())->toContain($cause);

    expect($output->files('/srv/addons'))->toBe([]);
})->with([
    'an addon that is not installed' => [true, '/srv/addons', 'reviews', GenerateErrorCode::PanelAddonUnknown, 'The addons cms:build compiled are: approvals, tally.'],
    'a registry cms:build has not written' => [false, '/srv/addons', 'tally', GenerateErrorCode::RegistryUnreadable, 'Run cms:build'],
    'a package Composer did not install' => [true, null, 'tally', GenerateErrorCode::OutputUnwritable, 'did not install the package acme/cms-tally'],
]);
