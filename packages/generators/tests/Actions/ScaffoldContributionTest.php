<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Actions\ScaffoldContribution;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ContributionRequest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;
use Cbox\Cms\Generators\Scaffold\Domain\IndexModule;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;
use Cbox\Cms\Generators\Tests\Scaffold\Fakes\FakeScaffoldOutput;
use Cbox\Cms\Generators\Tests\Scaffold\ScaffoldWorld;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use PHPUnit\Framework\Assert;

/*
 * cms:make:panel's action called directly with its ContributionRequest (GUARDRAILS 9): the
 * registry adapters over a fake registry cache with the world's registry, the fake samples and
 * the fake scaffold output. A compiled contribution takes its point, command, query, severity,
 * position and paths from the registry; one the manifest does not have yet takes them from the
 * request and gets the manifest line to add; each is added to the registration and the ids, or
 * noted when the addon laid them out otherwise; and a kind the registry or the point does not
 * take, a missing point and a point no package declares are refused.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * @return array{ScaffoldContribution, FakeScaffoldOutput}
 */
function scaffoldContribution(): array
{
    $cache = ScaffoldWorld::cache(ScaffoldWorld::registry());
    $output = new FakeScaffoldOutput;
    $output->put(ScaffoldWorld::ROOT.'/composer.json', ScaffoldWorld::composerJson());

    return [
        new ScaffoldContribution(ScaffoldWorld::addons($cache, '/srv/addons'), ScaffoldWorld::points($cache), ScaffoldWorld::fakeSamples(), $output),
        $output,
    ];
}

function tallyRequest(ScaffoldKind $kind, string $id, ?string $point = null, ?string $command = null, ?string $query = null): ContributionRequest
{
    return new ContributionRequest(
        $kind,
        new AddonNamespace('tally'),
        new ContributionId($id),
        $point === null ? null : PointId::fromString($point),
        $command === null ? null : CommandRef::fromString($command),
        $query === null ? null : CommandRef::fromString($query),
        Severity::Acknowledge,
        StepPosition::AfterReceipt,
        ['fields.ext.tally.note'],
    );
}

it('scaffolds a compiled check from the registry and adds it to the registration and the ids', function (): void {
    [$action, $output] = scaffoldContribution();
    $output->put(ScaffoldWorld::ROOT.'/'.IndexModule::INDEX, IndexModule::index(new AddonNamespace('tally'), [new RegistrationEntry(new ContributionId('tally.badge'), "() => import('./Badge')")])->contents);
    $output->put(ScaffoldWorld::ROOT.'/'.IndexModule::IDS, IndexModule::ids(new AddonNamespace('tally'), ['tally.badge'])->contents);

    $report = $action->scaffold(tallyRequest(ScaffoldKind::Check, 'tally.title-check'));

    expect($report->written)->toBe([
        'resources/panel/src/TitleCheckCheck.test.ts',
        'resources/panel/src/TitleCheckCheck.ts',
        'resources/panel/src/ids.ts',
        'resources/panel/src/index.ts',
    ])
        ->and($report->notes)->toBe([])
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/'.IndexModule::INDEX))->toContain(
            "import type { Contributions } from '../generated/contributions';\nimport { titleCheckCheck } from './TitleCheckCheck';\n",
            "export default definePanelAddon<Contributions>({\n  'tally.title-check': titleCheckCheck,\n  'tally.badge': () => import('./Badge'),\n});",
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/'.IndexModule::IDS))->toContain("  'tally.badge',\n  'tally.title-check',\n")
        // The registry's severity, warning, wins over the request's.
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/TitleCheckCheck.test.ts'))->toContain("severity: 'warning',");
});

it('scaffolds a fill the manifest does not have yet, with the registration and the manifest line to add', function (): void {
    [$action, $output] = scaffoldContribution();

    $report = $action->scaffold(tallyRequest(ScaffoldKind::Fill, 'tally.extra', 'notes.detail.card@1', query: 'tally.notes@1'));

    expect($report->written)->toBe([
        'resources/panel/src/Extra.test.tsx',
        'resources/panel/src/Extra.tsx',
        'resources/panel/src/ids.ts',
        'resources/panel/src/index.test.ts',
        'resources/panel/src/index.ts',
    ])
        ->and($report->notes)->toBe([
            "Add the contribution to the manifest's PanelContributions in the addon's service provider: new SlotFill(new ContributionId('tally.extra'), 'notes.detail.card@1', data: <the class of the query tally.notes@1>::class). Then run cms:build and cms:panel:types tally, so the generated types have it.",
        ])
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/'.IndexModule::INDEX))->toContain("'tally.extra': () => import('./Extra'),")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/'.IndexModule::IDS))->toContain("'tally.extra',")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/Extra.tsx'))->toContain('SlotProps<ViewerSummaryV1, TallyNotesResultV1>');
});

it('scaffolds a step and an action the manifest does not have yet from the request', function (): void {
    [$action, $output] = scaffoldContribution();

    $step = $action->scaffold(tallyRequest(ScaffoldKind::Step, 'tally.review', 'notes.form.steps@1', 'entry.create@1'));
    $menu = $action->scaffold(tallyRequest(ScaffoldKind::Action, 'tally.request-more', 'shell.user-menu@1', 'entry.create@1'));

    expect($step->written)->toContain('resources/panel/src/ReviewStep.tsx', 'resources/panel/src/ReviewStep.test.tsx')
        ->and($step->notes[0])->toContain("new FlowStep(new ContributionId('tally.review'), 'notes.form.steps@1', 'entry.create@1', StepPosition::AfterReceipt, patches: ['fields.ext.tally.note'])")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ReviewStep.tsx'))->toContain("StepProps<EntryCreateV1, 'fields.ext.tally.note', Issues>", 'after receipt')
        ->and($menu->written)->toBe([])
        ->and($menu->notes)->toBe([
            "Add the contribution to the manifest's PanelContributions in the addon's service provider: new ActionContribution(new ContributionId('tally.request-more'), 'shell.user-menu@1', 'entry.create@1', 'tally.request-more.label'). Then run cms:build and cms:panel:types tally, so the generated types have it.",
        ]);
});

it('asks for the registration by hand when the addon laid the modules out otherwise', function (): void {
    [$action, $output] = scaffoldContribution();
    $output->put(ScaffoldWorld::ROOT.'/'.IndexModule::INDEX, "export default definePanelAddon<Contributions>(contributions);\n");
    $output->put(ScaffoldWorld::ROOT.'/'.IndexModule::IDS, "export const CONTRIBUTIONS = ids;\n");

    $report = $action->scaffold(tallyRequest(ScaffoldKind::Check, 'tally.title-check'));

    expect($report->written)->toBe(['resources/panel/src/TitleCheckCheck.test.ts', 'resources/panel/src/TitleCheckCheck.ts'])
        ->and($report->notes)->toBe([
            'Add the contribution to the registration in resources/panel/src/index.ts by hand: tally.title-check: titleCheckCheck.',
            'Add tally.title-check to the ids in resources/panel/src/ids.ts by hand.',
        ]);
});

it('refuses what it cannot scaffold, and writes nothing', function (ContributionRequest $request, GenerateErrorCode $code, string $cause): void {
    [$action, $output] = scaffoldContribution();

    try {
        $action->scaffold($request);
    } catch (GenerationFailed $refused) {
        expect($refused->problems[0]->code)->toBe($code)
            ->and($refused->problems[0]->describe())->toContain($cause)
            ->and($output->files(ScaffoldWorld::ROOT))->toBe(['composer.json']);

        return;
    }

    Assert::fail('The request was scaffolded.');
})->with([
    'a compiled check asked for as a fill' => [tallyRequest(ScaffoldKind::Fill, 'tally.title-check'), GenerateErrorCode::PanelContributionMismatch, 'The registry has the contribution tally.title-check as a form_check on notes.form.checks@1, not as a fill.'],
    'a fill on a form check point' => [tallyRequest(ScaffoldKind::Fill, 'tally.extra', 'notes.form.checks@1'), GenerateErrorCode::PanelContributionMismatch, 'The point notes.form.checks@1 is of the kind form_check, which takes no fill. Give a point of the kind slot.'],
    'a new contribution without its point' => [tallyRequest(ScaffoldKind::Fill, 'tally.extra'), GenerateErrorCode::PanelContributionMismatch, 'The registry has no contribution tally.extra, so the point it is on is needed: give --point=<name>@<version>'],
    'a point no package declares' => [tallyRequest(ScaffoldKind::Fill, 'tally.extra', 'notes.missing.card@1'), GenerateErrorCode::PanelPointUnknown, 'No installed package declares the panel point notes.missing.card@1. The points cms:build compiled are: notes.detail.card@1, notes.form.checks@1, notes.form.steps@1, shell.nav@1, shell.user-menu@1.'],
    'an addon that is not installed' => [new ContributionRequest(ScaffoldKind::Fill, new AddonNamespace('reviews'), new ContributionId('reviews.badge')), GenerateErrorCode::PanelAddonUnknown, 'No installed addon has the namespace reviews.'],
]);
