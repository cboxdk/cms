<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelTypes;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Boundary\JsonSchemaShapes;
use Cbox\Cms\Generators\PanelTypes\Domain\ContributionsModule;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\AddonUi;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ContractShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PointType;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;

/*
 * The module cms:panel:types writes (PRD 13.4): for the fixture addon reviews, with a contribution
 * of every kind that runs code, it is exactly the committed golden module, byte for byte;
 * js/panel-sdk/tests/panel-types.test.ts compiles that module against the SDK and holds
 * definePanelAddon<Contributions>() to it, and checks that Prettier leaves it as it is. To change
 * the module on purpose, change the generator and write its output over the golden file.
 */

function panelTypesModule(AddonUi $addon): string
{
    return ContributionsModule::source($addon);
}

it('writes exactly the committed golden module for an addon with a contribution of every kind', function (): void {
    expect(panelTypesModule(PanelTypesFixtures::addon('/srv/addons/acme/cms-reviews')))
        ->toBe((string) file_get_contents(__DIR__.'/'.PanelTypesFixtures::GOLDEN));
});

it('writes one path in the directory it owns', function (): void {
    $result = ContributionsModule::result(PanelTypesFixtures::addon('/srv/addons/acme/cms-reviews'));

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $result->files))->toBe(['resources/panel/generated/contributions.ts'])
        ->and($result->directories)->toBe(['resources/panel/generated']);
});

it('imports a stable point\'s props from /extend and an experimental point\'s from /experimental', function (): void {
    $module = panelTypesModule(PanelTypesFixtures::addon('/srv'));

    expect($module)->toContain("import type { NoteCardV1 } from '@cboxdk/cms-panel/experimental';")
        ->and($module)->toMatch('/^  NoteToolbarV1,$/m')
        ->and($module)->toContain("} from '@cboxdk/cms-panel/extend';");
});

it('refuses two documents that give one TypeScript name, and a document named as the module\'s own types', function (string $command, string $point, string $name): void {
    $schema = '{"type":"object","properties":{"a":{"type":"string"}}}';
    $addon = new AddonUi(
        new AddonNamespace('reviews'),
        '/srv',
        [
            new UiContribution(new ContributionId('reviews.check'), PointKind::FormCheck, new PointType(PointId::fromString('notes.form.checks@1'), $point, true), command: new ContractShape(CommandRef::fromString($command), JsonSchemaShapes::read($schema, 'a schema'))),
            new UiContribution(new ContributionId('reviews.summary'), PointKind::Slot, new PointType(PointId::fromString('notes.form.checks@1'), $point, true)),
        ],
        [new ContractShape(CommandRef::fromString('notes.create_v@1'), JsonSchemaShapes::read($schema, 'a schema'))],
    );

    expect(fn (): string => panelTypesModule($addon))->toThrow(GenerationFailed::class, $name);
})->with([
    'two commands' => ['notes_create.v@1', 'NoteChecksV1', 'NotesCreateVV1'],
    'a command and a point' => ['notes.form@1', 'NotesCreateVV1', 'NotesCreateVV1'],
    'the module\'s own' => ['notes.form@1', 'Issues', 'Issues'],
]);

it('refuses a contribution whose kind runs no code', function (): void {
    $addon = new AddonUi(new AddonNamespace('reviews'), '/srv', [new UiContribution(new ContributionId('reviews.nav'), PointKind::Nav, new PointType(PointId::fromString('shell.nav@1'), 'ShellNavV1', true))], []);

    $failed = null;

    try {
        panelTypesModule($addon);
    } catch (GenerationFailed $refused) {
        $failed = $refused;
    }

    expect($failed)->toBeInstanceOf(GenerationFailed::class);
    assert($failed instanceof GenerationFailed);
    expect($failed->problems[0]->code)->toBe(GenerateErrorCode::InvalidOutput)
        ->and($failed->problems[0]->describe())->toContain('reviews.nav is of the kind nav, which runs no code');
});

it('imports a point\'s props only for a member typed on them, so a check or a step alone imports no props', function (): void {
    $schema = '{"type":"object","properties":{"a":{"type":"string"}}}';
    $create = new ContractShape(CommandRef::fromString('note.create@1'), JsonSchemaShapes::read($schema, 'the schema of note.create@1'));
    $addon = new AddonUi(
        new AddonNamespace('reviews'),
        '/srv',
        [
            new UiContribution(new ContributionId('reviews.check'), PointKind::FormCheck, new PointType(PointId::fromString('notes.form.checks@1'), 'NoteChecksV1', true), command: $create),
            new UiContribution(new ContributionId('reviews.step'), PointKind::FlowStep, new PointType(PointId::fromString('notes.form.steps@1'), 'NoteStepsV1', false), command: $create),
            new UiContribution(new ContributionId('reviews.queue'), PointKind::Page, new PointType(PointId::fromString('notes.queue@1'), 'NoteQueueV1', false)),
        ],
        [],
    );

    $module = panelTypesModule($addon);

    expect($module)->not->toContain('NoteChecksV1', 'NoteStepsV1', 'NoteQueueV1', '@cboxdk/cms-panel/experimental')
        ->and($module)->toContain("readonly 'reviews.check': FormCheck<NoteCreateV1>;", "readonly 'reviews.step': Lazy<FlowStep<NoteCreateV1, never, Issues>>;", "readonly 'reviews.queue': Lazy<PageComponent>;");
});
