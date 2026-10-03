<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\Registry\Boundary\PanelBundles;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\FillSource;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\ApprovalReason;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\ArchiveApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\DenySelfApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\PendingApprovals;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\RequestApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\RequireApprover;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\StampApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\NoteSectionsV1;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\RequireNoteTitle;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\SearchNotes;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use LogicException;

/*
 * cms:build compiles the addons' panel contributions (PRD 13.4, 13.1, 13.8) onto the points the
 * scan roots declare: the fixture addon acme/cms-approvals contributes to the points of the host
 * fixture acme/notes, and each failing case breaks one rule, so the build refuses it with its own
 * code and writes nothing. The last tests hold the dataset to every registry_panel_* code and show
 * the warnings, the order and the installation's overrides.
 */

/**
 * The codes of the problems, each once, sorted.
 *
 * @param  list<BuildProblem>  $problems
 * @return list<string>
 */
function panelCodes(array $problems): array
{
    $codes = array_values(array_unique(array_map(static fn (BuildProblem $problem): string => $problem->code->value, $problems)));
    sort($codes, SORT_STRING);

    return $codes;
}

/**
 * The problems of a build of the addon with the contributions, its own experimental points
 * accepted, and otherwise the world's.
 *
 * @param  list<PanelContribution>  $contributions
 * @return list<BuildProblem>
 */
function panelRefusal(array $contributions): array
{
    return PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest($contributions)]));
}

/**
 * A fill of a compiled point by id.
 */
function panelFillOf(PanelPointEntry $point, string $id): PanelFill
{
    foreach ($point->fills as $fill) {
        if ($fill->contribution->value === $id) {
            return $fill;
        }
    }

    throw new LogicException(sprintf('%s has no fill %s.', $point->id()->toString(), $id));
}

function panelPoint(string $id): PanelPointEntry
{
    return PanelBuildWorld::build(PanelBuildWorld::addons([PanelBuildWorld::manifest(PanelBuildWorld::everyKind())]))->panelPoint(PointId::fromString($id))
        ?? throw new LogicException(sprintf('No point %s.', $id));
}

/**
 * The failing fixtures: each case's code, and the problems of a build that breaks its rule.
 *
 * @return array<string, array{string, callable(): list<BuildProblem>}>
 */
function panelFailures(): array
{
    $draft = new Scope(commands: [new CommandRef(new CommandName('notes.draft'), 1)]);
    $badge = static fn (string $point): SlotFill => new SlotFill(new ContributionId('approvals.badge'), $point);

    return [
        'a contribution to a point no #[PanelPoint] declares' => ['registry_panel_unknown_point', static fn (): array => panelRefusal([$badge('notes.nowhere@1')])],
        'a contribution to text that is no point id' => ['registry_panel_unknown_point', static fn (): array => panelRefusal([$badge('notes.detail.sections')])],
        'an experimental point accepted that does not exist' => ['registry_panel_unknown_point', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], ['notes.gone@3'])]))],
        'a contribution to an internal point' => ['registry_panel_internal_point', static fn (): array => panelRefusal([$badge('notes.wiring@1')])],
        'an internal point accepted as experimental' => ['registry_panel_internal_point', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], ['notes.wiring@1'])]))],
        'a slot fill at an action point' => ['registry_panel_kind_mismatch', static fn (): array => panelRefusal([$badge('notes.detail.actions@1')])],
        'a contribution to an experimental point the addon does not accept' => ['registry_panel_experimental_not_accepted', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([$badge('notes.detail.sections@1')], [])]))],
        'an id in another addon\'s namespace' => ['registry_panel_duplicate_contribution', static fn (): array => panelRefusal([new SlotFill(new ContributionId('stamps.badge'), 'notes.legacy@1')])],
        'an id given twice' => ['registry_panel_duplicate_contribution', static fn (): array => panelRefusal([$badge('notes.legacy@1'), $badge('notes.legacy@1')])],
        'an addon in the core\'s namespace cms' => ['registry_panel_duplicate_contribution', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], [], namespace: 'cms', package: PanelBuildWorld::STAMPS, bundle: null)]))],
        'a field type of another addon replaced' => ['registry_panel_unowned_target', static fn (): array => panelRefusal([new ReplacementContribution(new ContributionId('approvals.stars-input'), 'notes.form.field@1', 'stamps:stars')])],
        'a command of the host replaced' => ['registry_panel_unowned_target', static fn (): array => panelRefusal([new ReplacementContribution(new ContributionId('approvals.draft-form'), 'notes.form.command@1', 'notes.draft@1')])],
        'a value class of the host replaced' => ['registry_panel_unowned_target', static fn (): array => panelRefusal([new ReplacementContribution(new ContributionId('approvals.sections'), 'notes.form.value@1', NoteSectionsV1::class)])],
        'two addons replacing one key with no winner named' => ['registry_panel_replacement_conflict', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([
            PanelBuildWorld::manifest([new ReplacementContribution(new ContributionId('approvals.reason-input'), 'notes.form.any@1', ApprovalReason::class)]),
            PanelBuildWorld::manifest([new ReplacementContribution(new ContributionId('stamps.reason-input'), 'notes.form.any@1', ApprovalReason::class)], [], namespace: 'stamps', package: PanelBuildWorld::STAMPS),
        ]))],
        'a blocking check that mirrors nothing' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new FormCheck(new ContributionId('approvals.self-approval'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error)])],
        'a blocking check that mirrors a transform hook' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new FormCheck(new ContributionId('approvals.self-approval'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error, StampApproval::class)])],
        'a blocking check that mirrors the host\'s hook' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new FormCheck(new ContributionId('approvals.self-approval'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error, RequireNoteTitle::class)])],
        'a blocking check that mirrors a hook on another command' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new FormCheck(new ContributionId('approvals.self-approval'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error, RequireApprover::class)])],
        'a disabled reason scoped to no command' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new DecoratorContribution(new ContributionId('approvals.submit-guard'), 'notes.form.submit@1', [Tighten::DisabledReason], DenySelfApproval::class)])],
        'a disabled reason that mirrors no hook' => ['registry_panel_check_unmirrored', static fn (): array => panelRefusal([new DecoratorContribution(new ContributionId('approvals.submit-guard'), 'notes.form.submit@1', [Tighten::DisabledReason], scope: $draft)])],
        'a step that patches the host\'s field' => ['registry_panel_flow_path_unknown', static fn (): array => panelRefusal([new FlowStep(new ContributionId('approvals.four-eyes'), 'notes.form.steps@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['note'])])],
        'a step that patches another addon\'s extension field' => ['registry_panel_flow_path_unknown', static fn (): array => panelRefusal([new FlowStep(new ContributionId('approvals.four-eyes'), 'notes.form.steps@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['fields.ext.stamps.reason'])])],
        'a step that patches a path the schema does not have' => ['registry_panel_flow_path_unknown', static fn (): array => panelRefusal([new FlowStep(new ContributionId('approvals.request-step'), 'notes.form.steps@1', 'approvals.request@1', StepPosition::AfterReceipt, ['approver'])])],
        'an action whose command is not in issues' => ['registry_panel_command_not_issuable', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([new ActionContribution(new ContributionId('approvals.request'), 'notes.detail.actions@1', RequestApproval::class, 'approvals.request.label')], issues: [])]))],
        'a command in issues that is not exposed on Inertia' => ['registry_panel_command_not_issuable', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], issues: [RequestApproval::class, ArchiveApproval::class])]))],
        'a command in issues that is no command' => ['registry_panel_command_not_issuable', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], issues: [ApprovalReason::class])]))],
        'a prefill from a pointer the props do not have' => ['registry_panel_action_prefill_invalid', static fn (): array => panelRefusal([new ActionContribution(new ContributionId('approvals.request'), 'notes.detail.actions@1', RequestApproval::class, 'approvals.request.label', prefill: ['note' => '/title'])])],
        'a prefill of a property the command does not have' => ['registry_panel_action_prefill_invalid', static fn (): array => panelRefusal([new ActionContribution(new ContributionId('approvals.request'), 'notes.detail.actions@1', RequestApproval::class, 'approvals.request.label', prefill: ['approver' => '/note'])])],
        'a prefill of an integer into a string' => ['registry_panel_action_prefill_invalid', static fn (): array => panelRefusal([new ActionContribution(new ContributionId('approvals.request'), 'notes.detail.actions@1', RequestApproval::class, 'approvals.request.label', prefill: ['reason' => '/count'])])],
        'a data query of another package' => ['registry_panel_data_query_invalid', static fn (): array => panelRefusal([new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1', SearchNotes::class)])],
        'a data query that is no query' => ['registry_panel_data_query_invalid', static fn (): array => panelRefusal([new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1', RequestApproval::class)])],
        'a data query whose input the props do not give' => ['registry_panel_data_query_invalid', static fn (): array => PanelBuildWorld::refused(
            PanelBuildWorld::addons([PanelBuildWorld::manifest([new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1', PendingApprovals::class)])]),
            shapes: PanelBuildWorld::shapes(['notes.detail.sections@1' => '{"type": "object", "additionalProperties": false, "properties": {"note": {"type": "integer"}}}']),
        )],
        'contributions that run code without a bundle' => ['registry_panel_bundle_invalid', static fn (): array => panelRefusalWithout([$badge('notes.legacy@1')])],
        'a bundle that imports a module the panel does not share' => ['registry_panel_bundle_invalid', static function () use ($badge): array {
            $manifest = PanelBuildWorld::manifest([$badge('notes.legacy@1')]);

            return PanelBuildWorld::refused(PanelBuildWorld::addons([$manifest], [PanelBuildWorld::ADDON => PanelBuildWorld::bundle($manifest, externals: ['react', 'lodash'])]));
        }],
        'a bundle whose contributions differ from the manifest' => ['registry_panel_bundle_invalid', static function () use ($badge): array {
            $manifest = PanelBuildWorld::manifest([$badge('notes.legacy@1')]);

            return PanelBuildWorld::refused(PanelBuildWorld::addons([$manifest], [PanelBuildWorld::ADDON => PanelBuildWorld::bundle($manifest, ['approvals.other'])]));
        }],
        'a bundle whose files changed, are missing or leave their layer' => ['registry_panel_bundle_invalid', static function (): array {
            $manifest = PanelBuildWorld::manifest([new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1')]);

            return PanelBuildWorld::refused(PanelBuildWorld::addons([$manifest], [PanelBuildWorld::ADDON => PanelBundles::read(__DIR__.'/../Registry/Fixtures/PanelBundle/dist')]));
        }],
        'a check for a command form no scan root registers' => ['registry_panel_unknown_command', static fn (): array => panelRefusal([new FormCheck(new ContributionId('approvals.hint'), 'notes.form.checks@1', 'notes.publish@1', Severity::Warning)])],
        'a scope that requires an unknown permission' => ['registry_panel_unknown_command', static fn (): array => panelRefusal([new SlotFill(new ContributionId('approvals.legacy'), 'notes.legacy@1', scope: new Scope(requires: new CommandName('notes.publish')))])],
        'a scope of an unknown command' => ['registry_panel_unknown_command', static fn (): array => panelRefusal([new SlotFill(new ContributionId('approvals.legacy'), 'notes.legacy@1', scope: new Scope(commands: [new CommandRef(new CommandName('notes.draft'), 2)]))])],
        'a priority set for no contribution of the point' => ['registry_panel_override_invalid', static fn (): array => PanelBuildWorld::refused(
            PanelBuildWorld::addons([PanelBuildWorld::manifest([$badge('notes.legacy@1')])]),
            PanelBuildWorld::settings([new ContributionOverride(PointId::fromString('notes.detail.sections@1'), new ContributionId('approvals.badge'), 5)]),
        )],
        'a winner that is no replacement of the key' => ['registry_panel_override_invalid', static fn (): array => PanelBuildWorld::refused(
            PanelBuildWorld::addons([PanelBuildWorld::manifest([new ReplacementContribution(new ContributionId('approvals.reason-input'), 'notes.form.any@1', ApprovalReason::class)])]),
            PanelBuildWorld::settings(replacements: [new ReplacementChoice(PointId::fromString('notes.form.any@1'), ApprovalReason::class, new ContributionId('stamps.reason-input'))]),
        )],
        'a decorator that tightens what its point does not declare' => ['registry_panel_tightening_undeclared', static fn (): array => panelRefusal([new DecoratorContribution(new ContributionId('approvals.submit-tone'), 'notes.form.submit@1', [Tighten::ToneTowardsDanger])])],
        'a nav entry to a page the addon does not contribute' => ['registry_panel_nav_target_unknown', static fn (): array => panelRefusal([new NavContribution(new ContributionId('approvals.queue-link'), 'notes.nav@1', 'approvals.nav.queue', 'approvals.queue')])],
        'a #[PanelPoint] without a stability attribute' => ['registry_panel_point_without_stability', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([]), roots: new ScanRoots(RegistryFixtures::root('PanelPointWithoutStability')))],
        'an older version of a point without a downcast' => ['registry_panel_point_without_downcast', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([]), roots: new ScanRoots(RegistryFixtures::root('PanelPointWithoutDowncast')))],
        'contributions that need a newer panel API' => ['registry_incompatible_panel_api', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([], sdk: new PanelApiVersion(PanelApiVersion::CURRENT_MAJOR + 1, 0))]))],
        'a selected theme the addon does not ship' => ['registry_panel_theme_invalid', static fn (): array => PanelBuildWorld::refused(
            PanelBuildWorld::addons([PanelBuildWorld::manifest([])]),
            PanelBuildWorld::settings(themes: new ThemeSelection([new ThemeName('approvals:pale')])),
        )],
        'selected themes that compose below AA' => ['registry_panel_theme_contrast', static fn (): array => PanelBuildWorld::refused(
            PanelBuildWorld::addons([PanelBuildWorld::manifest([], themes: ['pale' => array_key_first(PanelBuildWorld::THEMES)])]),
            PanelBuildWorld::settings(themes: new ThemeSelection([new ThemeName('approvals:pale')])),
        )],
        'an addon the installation\'s allowlist does not name' => ['registry_addon_not_allowed', static fn (): array => PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest([])]), PanelBuildWorld::settings(allowed: [PanelBuildWorld::STAMPS]))],
    ];
}

/**
 * The problems of a build of the addon with the contributions and no bundle.
 *
 * @param  list<PanelContribution>  $contributions
 * @return list<BuildProblem>
 */
function panelRefusalWithout(array $contributions): array
{
    return PanelBuildWorld::refused(PanelBuildWorld::addons([PanelBuildWorld::manifest($contributions, bundle: null)]));
}

it('builds a contribution of every kind into panel.php and the addon into addons.php', function (): void {
    $registry = PanelBuildWorld::build(PanelBuildWorld::addons([PanelBuildWorld::manifest(PanelBuildWorld::everyKind())]));
    $addon = $registry->addons[0];
    $action = panelFillOf($registry->panelPoint(PointId::fromString('notes.detail.actions@1')) ?? throw new LogicException, 'approvals.request');
    $badge = panelFillOf($registry->panelPoint(PointId::fromString('notes.detail.sections@1')) ?? throw new LogicException, 'approvals.badge');
    $step = panelFillOf($registry->panelPoint(PointId::fromString('notes.form.steps@1')) ?? throw new LogicException, 'approvals.four-eyes');

    expect(array_sum(array_map(static fn (PanelPointEntry $point): int => count($point->fills), $registry->panel)))->toBe(count(PanelBuildWorld::everyKind()))
        ->and($registry->panelPoint(PointId::fromString('notes.wiring@1'))?->fills)->toBe([])
        ->and($action->command?->toString())->toBe('approvals.request@1')
        ->and($action->priority)->toBe(50)
        ->and($badge->query?->toString())->toBe('approvals.pending@1')
        ->and($step->command?->toString())->toBe('notes.draft@1')
        ->and($addon->namespace->value)->toBe('approvals')
        ->and(array_map(static fn (IssuedCommand $issued): string => $issued->command->toString(), $addon->issues))->toBe(['approvals.request@1'])
        ->and($addon->uiTheme)->toBeTrue()
        ->and($addon->panel?->sdk->toString())->toBe('1.0')
        ->and(array_map(static fn (PointId $point): string => $point->toString(), $addon->panel->acceptsExperimental ?? []))->toBe(['notes.detail.sections@1'])
        ->and($addon->panel?->bundle?->entry->value)->toBe('addon.js');
});

it('refuses each broken rule with its own code and nothing else', function (string $code, callable $refused): void {
    $problems = $refused();
    expect($problems)->toBeArray();

    expect(panelCodes(array_values(array_filter(is_array($problems) ? $problems : [], static fn (mixed $problem): bool => $problem instanceof BuildProblem))))->toBe([$code]);
})->with(panelFailures());

it('has a failing fixture for every registry_panel_ code, the panel API and the allowlist', function (): void {
    $codes = array_values(array_unique(array_map(static fn (array $case): string => $case[0], panelFailures())));
    $panel = array_values(array_filter(
        array_map(static fn (BuildErrorCode $code): string => $code->value, BuildErrorCode::cases()),
        static fn (string $code): bool => str_starts_with($code, 'registry_panel_'),
    ));
    sort($codes, SORT_STRING);
    $expected = [...$panel, 'registry_addon_not_allowed', 'registry_incompatible_panel_api'];
    sort($expected, SORT_STRING);

    expect($codes)->toBe($expected);
});

it('warns about a contribution to an experimental and to a deprecated point and still builds', function (): void {
    $registry = PanelBuildWorld::build(PanelBuildWorld::addons([PanelBuildWorld::manifest(PanelBuildWorld::everyKind())]));

    expect(array_map(static fn (BuildWarning $warning): string => $warning->code, $registry->warnings))->toBe([BuildWarning::CODE_POINT_EXPERIMENTAL, BuildWarning::CODE_POINT_DEPRECATED])
        ->and($registry->warnings[0]->message)->toContain('approvals.badge')->toContain('notes.detail.sections@1')->toContain('may change in a minor release')
        ->and($registry->warnings[1]->message)->toContain('approvals.legacy')->toContain('deprecated since panel API 1.0 and is removed in 2.0')->toContain('Move it to notes.detail.sections@1');
});

it('renders by priority, then namespace, then id, and applies the installation\'s order and enabled state', function (): void {
    $first = new SlotFill(new ContributionId('approvals.zeta'), 'notes.legacy@1', priority: 300);
    $second = new SlotFill(new ContributionId('approvals.alpha'), 'notes.legacy@1', priority: 300);
    $third = new SlotFill(new ContributionId('stamps.mark'), 'notes.legacy@1', priority: 100);
    $registry = PanelBuildWorld::build(
        PanelBuildWorld::addons([PanelBuildWorld::manifest([$first, $second]), PanelBuildWorld::manifest([$third], [], namespace: 'stamps', package: PanelBuildWorld::STAMPS)]),
        PanelBuildWorld::settings([
            new ContributionOverride(PointId::fromString('notes.legacy@1'), new ContributionId('approvals.zeta'), 10),
            new ContributionOverride(PointId::fromString('notes.legacy@1'), new ContributionId('stamps.mark'), enabled: false),
        ]),
    );
    $fills = ($registry->panelPoint(PointId::fromString('notes.legacy@1')) ?? throw new LogicException)->fills;

    expect(array_map(static fn (PanelFill $fill): string => $fill->contribution->value, $fills))->toBe(['approvals.zeta', 'stamps.mark', 'approvals.alpha'])
        ->and(array_map(static fn (PanelFill $fill): array => [$fill->priority, $fill->ordering, $fill->enabled, $fill->enabling], $fills))->toBe([
            [10, FillSource::Installation, true, FillSource::Addon],
            [100, FillSource::Addon, false, FillSource::Installation],
            [300, FillSource::Addon, true, FillSource::Addon],
        ]);
});

it('makes the replacement the installation names win its key and passes over the others', function (): void {
    $registry = PanelBuildWorld::build(
        PanelBuildWorld::addons([
            PanelBuildWorld::manifest([new ReplacementContribution(new ContributionId('approvals.reason-input'), 'notes.form.any@1', ApprovalReason::class)]),
            PanelBuildWorld::manifest([new ReplacementContribution(new ContributionId('stamps.reason-input'), 'notes.form.any@1', ApprovalReason::class)], [], namespace: 'stamps', package: PanelBuildWorld::STAMPS),
        ]),
        PanelBuildWorld::settings(replacements: [new ReplacementChoice(PointId::fromString('notes.form.any@1'), ApprovalReason::class, new ContributionId('stamps.reason-input'))]),
    );
    $point = $registry->panelPoint(PointId::fromString('notes.form.any@1')) ?? throw new LogicException;

    expect([panelFillOf($point, 'stamps.reason-input')->enabled, panelFillOf($point, 'stamps.reason-input')->enabling])->toBe([true, FillSource::Installation])
        ->and([panelFillOf($point, 'approvals.reason-input')->enabled, panelFillOf($point, 'approvals.reason-input')->enabling])->toBe([false, FillSource::Installation]);
});

it('lets a step patch any path of the addon\'s own command and a check warn without a mirror', function (): void {
    expect(panelPoint('notes.form.steps@1')->fills)->toHaveCount(2)
        ->and(panelFillOf(panelPoint('notes.form.checks@1'), 'approvals.hint')->declaration)->toBeInstanceOf(FormCheck::class);
});
