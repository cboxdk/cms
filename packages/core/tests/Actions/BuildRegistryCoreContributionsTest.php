<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\PanelCompiler;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\DraftNote;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use LogicException;

/*
 * The core's own panel contributions, in the namespace cms (PRD 13.4), compile with the addons':
 * at the core's priorities, before an addon's at its default, held to the points, the kinds and the
 * ids as an addon's, and free of what limits an addon to what it owns: the core contributes to an
 * #[Internal] point and to an experimental one without opting in, runs any command exposed on
 * Inertia, tightens a submit and patches any path without mirroring a hook, and has no bundle.
 */

/**
 * The addon's manifest with a slot fill at notes.detail.sections@1, and the core's contributions.
 *
 * @param  list<PanelContribution>  $core
 */
function coreAndAddon(array $core): DeclaredAddons
{
    $addons = PanelBuildWorld::addons([PanelBuildWorld::manifest([new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1')])]);

    return new DeclaredAddons($addons->manifests, $addons->problems, $addons->bundles, $core);
}

function coreFillOf(CompiledRegistry $registry, string $point, string $id): PanelFill
{
    foreach (($registry->panelPoint(PointId::fromString($point)) ?? throw new LogicException(sprintf('No point %s.', $point)))->fills as $fill) {
        if ($fill->contribution->value === $id) {
            return $fill;
        }
    }

    throw new LogicException(sprintf('%s has no fill %s.', $point, $id));
}

/**
 * The codes of a build's problems, in order.
 *
 * @param  list<PanelContribution>  $core
 * @return list<string>
 */
function coreRefusal(array $core): array
{
    return array_map(static fn (BuildProblem $problem): string => $problem->code->value, PanelBuildWorld::refused(coreAndAddon($core)));
}

it('compiles the core\'s contributions into panel.php before the addons\' at their default, with the core\'s package', function (): void {
    $registry = PanelBuildWorld::build(coreAndAddon([
        new SlotFill(new ContributionId('cms.summary'), 'notes.detail.sections@1', priority: 100),
        new SlotFill(new ContributionId('cms.wiring'), 'notes.wiring@1', priority: 200),
        new ActionContribution(new ContributionId('cms.draft'), 'notes.detail.actions@1', DraftNote::class, 'panel.notes.draft', priority: 100),
        new DecoratorContribution(new ContributionId('cms.submit-note'), 'notes.form.submit@1', [Tighten::DisabledReason, Tighten::Description]),
        new FormCheck(new ContributionId('cms.title-check'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error),
        new FlowStep(new ContributionId('cms.confirm-note'), 'notes.form.steps@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['note']),
    ]));
    $sections = ($registry->panelPoint(PointId::fromString('notes.detail.sections@1')) ?? throw new LogicException('No point notes.detail.sections@1.'))->fills;

    expect(array_map(static fn (PanelFill $fill): string => $fill->contribution->value, $sections))->toBe(['cms.summary', 'approvals.badge'])
        ->and(coreFillOf($registry, 'notes.detail.sections@1', 'cms.summary')->package)->toBe(PanelCompiler::CORE_PACKAGE)
        ->and(coreFillOf($registry, 'notes.detail.sections@1', 'cms.summary')->addon()->value)->toBe(PanelCompiler::CORE_NAMESPACE)
        ->and(coreFillOf($registry, 'notes.wiring@1', 'cms.wiring')->enabled)->toBeTrue()
        ->and(coreFillOf($registry, 'notes.detail.actions@1', 'cms.draft')->command?->toString())->toBe('notes.draft@1')
        ->and(coreFillOf($registry, 'notes.form.checks@1', 'cms.title-check')->command?->toString())->toBe('notes.draft@1')
        ->and(coreFillOf($registry, 'notes.form.steps@1', 'cms.confirm-note')->command?->toString())->toBe('notes.draft@1')
        ->and(coreFillOf($registry, 'notes.form.submit@1', 'cms.submit-note')->declaration)->toBeInstanceOf(DecoratorContribution::class)
        // The addon's contribution to the experimental point warns; the core's never does.
        ->and(array_map(static fn (BuildWarning $warning): string => $warning->message, $registry->warnings))->each->not->toContain('cms.')
        ->and(array_map(static fn (AddonEntry $addon): string => $addon->namespace->value, $registry->addons))->toBe(['approvals']);
});

/*
 * @param  list<PanelContribution>  $core
 */
it('refuses a core contribution outside the namespace cms, twice, at an unknown point or of another kind', function (array $core, string $code): void {
    $core = array_values(array_filter($core, static fn (mixed $contribution): bool => $contribution instanceof PanelContribution));

    expect(coreRefusal($core))->toBe([$code]);
})->with([
    'an id in an addon\'s namespace' => [[new SlotFill(new ContributionId('approvals.summary'), 'notes.detail.sections@1')], 'registry_panel_duplicate_contribution'],
    'an id given twice' => [[new SlotFill(new ContributionId('cms.summary'), 'notes.detail.sections@1'), new SlotFill(new ContributionId('cms.summary'), 'notes.wiring@1')], 'registry_panel_duplicate_contribution'],
    'a point no #[PanelPoint] declares' => [[new SlotFill(new ContributionId('cms.summary'), 'notes.nowhere@1')], 'registry_panel_unknown_point'],
    'a slot fill at an action point' => [[new SlotFill(new ContributionId('cms.summary'), 'notes.detail.actions@1')], 'registry_panel_kind_mismatch'],
    'a check for a command no scan root registers' => [[new FormCheck(new ContributionId('cms.check'), 'notes.form.checks@1', 'notes.gone@1', Severity::Warning)], 'registry_panel_unknown_command'],
]);
