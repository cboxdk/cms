<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Actions\WritePanelTypes;
use Cbox\Cms\Generators\Scaffold\Actions\ScaffoldAddonUi;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonUiRequest;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use Cbox\Cms\Generators\Tests\Scaffold\Fakes\FakeScaffoldOutput;
use Cbox\Cms\Generators\Tests\Scaffold\ScaffoldWorld;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1;
use PHPUnit\Framework\Assert;

/*
 * cms:make:addon-ui's action called directly with its AddonUiRequest (GUARDRAILS 9): the registry
 * adapters over a fake registry cache with the world's registry, the fake samples, the fake
 * scaffold output with the addon's composer.json, and cms:panel:types' action over the fake
 * generated output. It writes the generated types, the skeleton, a stub and a test per fill,
 * check and step, the registration with its test and the ids, keeps what the addon has, notes a
 * contribution it writes no stub for, and refuses an addon that is not installed and a point no
 * package declares.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * @return array{ScaffoldAddonUi, FakeScaffoldOutput, FakeGeneratedOutput}
 */
function scaffoldAddonUi(?CompiledRegistry $registry = null, ?RegistryCache $points = null): array
{
    $cache = ScaffoldWorld::cache($registry ?? ScaffoldWorld::registry());
    $addons = ScaffoldWorld::addons($cache, '/srv/addons');
    $output = new FakeScaffoldOutput;
    $output->put(ScaffoldWorld::ROOT.'/composer.json', ScaffoldWorld::composerJson());
    $generated = new FakeGeneratedOutput;

    return [
        new ScaffoldAddonUi($addons, ScaffoldWorld::points($points ?? $cache), ScaffoldWorld::fakeSamples(), $output, new WritePanelTypes($addons, $generated)),
        $output,
        $generated,
    ];
}

it('scaffolds the panel UI of an installed addon from the contributions cms:build compiled', function (): void {
    [$action, $output, $generated] = scaffoldAddonUi();

    $report = $action->scaffold(new AddonUiRequest(new AddonNamespace('tally')));

    expect($report->written)->toBe([
        '.prettierrc',
        'eslint.config.js',
        'package.json',
        'resources/panel/src/Badge.test.tsx',
        'resources/panel/src/Badge.tsx',
        'resources/panel/src/ConfirmStep.test.tsx',
        'resources/panel/src/ConfirmStep.tsx',
        'resources/panel/src/TitleCheckCheck.test.ts',
        'resources/panel/src/TitleCheckCheck.ts',
        'resources/panel/src/ids.ts',
        'resources/panel/src/index.test.ts',
        'resources/panel/src/index.ts',
        'tests/Panel/PanelContributionsTest.php',
        'tsconfig.json',
        'vite.config.ts',
        'vitest.config.ts',
    ])
        ->and($report->kept)->toBe([])
        ->and($report->notes)->toBe(['Install the dependencies with npm install, then run npm run typecheck, npm run lint and npm run test; npm run build writes dist/panel, which the manifest names as the bundle.'])
        ->and((string) $generated->contents(ScaffoldWorld::ROOT.'/resources/panel/generated/contributions.ts'))->toContain("readonly 'tally.badge': Lazy<SlotComponent<ViewerSummaryV1, TallyNotesResultV1>>;")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/index.ts'))->toContain(
            "import { definePanelAddon } from '@cboxdk/cms-panel/extend';",
            "import type { Contributions } from '../generated/contributions';",
            "import { titleCheckCheck } from './TitleCheckCheck';",
            "export default definePanelAddon<Contributions>({\n  'tally.badge': () => import('./Badge'),\n  'tally.confirm': () => import('./ConfirmStep'),\n  'tally.title-check': titleCheckCheck,\n});",
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ids.ts'))->toBe(
            "// The ids of the contributions of tally that run code, as its manifest declares them: what\n"
            ."// the registration holds and what the build plugin names in panel-manifest.json. Written by\n"
            ."// cms:make:addon-ui from the registry; cms:make:panel adds to it.\n\n"
            ."export const CONTRIBUTIONS: readonly string[] = [\n  'tally.badge',\n  'tally.confirm',\n  'tally.title-check',\n];\n",
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/Badge.tsx'))->toContain(
            "import { usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';",
            "import type { ViewerSummaryV1 } from '@cboxdk/cms-panel/experimental';",
            "import type { Issues, TallyNotesResultV1 } from '../generated/contributions';",
            'export default function Badge(input: SlotProps<ViewerSummaryV1, TallyNotesResultV1>) {',
            "if (input.data.status !== 'ready') {",
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/Badge.test.tsx'))->toContain(
            '// @vitest-environment jsdom',
            'const rendered = await expectSlotContract<typeof addon.contributions, TallyNotesResultV1>({',
            "    props: { actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01', issuer: 'human' },",
            '    data: { count: 0 },',
            "    host: { namespace: 'tally' },",
            'await expectNoA11yViolations(rendered.container);',
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/TitleCheckCheck.ts'))->toContain(
            "import type { FormCheck } from '@cboxdk/cms-panel/extend';",
            "import type { EntryCreateV1 } from '../generated/contributions';",
            'export const titleCheckCheck: FormCheck<EntryCreateV1> = () => [];',
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/TitleCheckCheck.test.ts'))->toContain(
            "const document: EntryCreateV1 = { entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01', fields: {} };",
            "    severity: 'warning',",
            '    documents: [document],',
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ConfirmStep.tsx'))->toContain(
            "import { usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';",
            "export default function ConfirmStep(step: StepProps<EntryCreateV1, 'fields.ext.tally.reason', Issues>) {",
            '<button type="button" onClick={step.next}>',
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ConfirmStep.test.tsx'))->toContain("    patches: ['fields.ext.tally.reason'],")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/index.test.ts'))->toContain('expectRegistration(addon, CONTRIBUTIONS);')
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/tests/Panel/PanelContributionsTest.php'))->toContain(
            'namespace Acme\Tally\Tests\Panel;',
            'use Acme\Tally\TallyServiceProvider;',
            'use PanelContributionsContract;',
            'return new TallyServiceProvider(app())->addonManifest();',
        )
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/package.json'))->toContain('"name": "@acme/cms-tally",', '"@cboxdk/cms-panel": "0.1.0"', '"verify": "cms-panel-addon verify"')
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/vite.config.ts'))->toContain("plugins: [cmsPanelAddon({ namespace: 'tally', contributions: [...CONTRIBUTIONS] })],");
});

it('keeps the files the addon has, and writes the ids anew from the registry', function (): void {
    [$action, $output] = scaffoldAddonUi();
    $output->put(ScaffoldWorld::ROOT.'/package.json', "{\n  \"name\": \"the author's\"\n}\n");
    $output->put(ScaffoldWorld::ROOT.'/resources/panel/src/index.ts', "export default {};\n");
    $output->put(ScaffoldWorld::ROOT.'/resources/panel/src/ids.ts', "export const CONTRIBUTIONS: readonly string[] = ['stale.id'];\n");

    $report = $action->scaffold(new AddonUiRequest(new AddonNamespace('tally')));

    expect($report->kept)->toBe(['package.json', 'resources/panel/src/index.ts'])
        ->and($report->written)->toContain('resources/panel/src/ids.ts')
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/package.json'))->toBe("{\n  \"name\": \"the author's\"\n}\n")
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ids.ts'))->not->toContain('stale.id');

    $again = $action->scaffold(new AddonUiRequest(new AddonNamespace('tally')));

    expect($again->written)->toBe([])
        ->and($again->kept)->toHaveCount(16);
});

it('notes a contribution of a kind it writes no stub for', function (): void {
    $submit = new PanelPoint('notes.form.submit', 1, PointKind::Decorator, 'notes.form', '1.0', 'fixture.points.note_submit', tightens: [Tighten::Description]);
    $registry = new CompiledRegistry(
        commands: [],
        hooks: [],
        panel: [
            new PanelPointEntry($submit, ShellPageV1::class, 'cboxdk/cms', PointStability::Experimental, [
                new PanelFill(new DecoratorContribution(new ContributionId('tally.guard'), 'notes.form.submit@1', [Tighten::Description]), ScaffoldWorld::PACKAGE, 1000),
            ]),
        ],
        addons: [new AddonEntry(new AddonNamespace('tally'), ScaffoldWorld::PACKAGE, new CoreApiVersion(1, 0), ClassificationAccess::Public, [], false)],
    );
    [$action, $output] = scaffoldAddonUi($registry, ScaffoldWorld::cache(ScaffoldWorld::registry()));

    $report = $action->scaffold(new AddonUiRequest(new AddonNamespace('tally')));

    expect($report->notes[0])->toBe('The contribution tally.guard is of the kind decorator, which cms:make:panel writes no stub for: register it in resources/panel/src/index.ts by hand.')
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/index.ts'))->toContain('export default definePanelAddon<Contributions>({});')
        ->and((string) $output->contents(ScaffoldWorld::ROOT.'/resources/panel/src/ids.ts'))->toContain("'tally.guard',");
});

it('refuses an addon that is not installed, and a point no package declares, and writes nothing', function (): void {
    [$action, $output] = scaffoldAddonUi();

    expect(fn () => $action->scaffold(new AddonUiRequest(new AddonNamespace('reviews'))))
        ->toThrow(GenerationFailed::class, 'No installed addon has the namespace reviews.');

    [$action, $output] = scaffoldAddonUi(points: ScaffoldWorld::cache(CompiledRegistry::empty()));

    try {
        $action->scaffold(new AddonUiRequest(new AddonNamespace('tally')));
    } catch (GenerationFailed $refused) {
        expect($refused->problems[0]->code)->toBe(GenerateErrorCode::PanelPointUnknown)
            ->and($refused->problems[0]->describe())->toContain('No installed package declares the panel point notes.detail.card@1.')
            ->and($output->files(ScaffoldWorld::ROOT))->toBe(['composer.json']);

        return;
    }

    Assert::fail('A point no package declares was scaffolded for.');
});
