<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Cbox\Cms\Core\Registry\Domain\PanelCompiler;
use Cbox\Cms\Core\Registry\Domain\PointDowncastRefused;
use Cbox\Cms\Core\Registry\Domain\PointDowncasts;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActivePoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointInScope;
use Cbox\Cms\Panel\Contributions\Domain\Registrations;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;

/**
 * Works out, per request and page, the contributions a viewer gets (PRD 13.4):
 *
 * 1. The points the page renders: every version of each point the page lists, as the compiled
 *    registry declares them on the page, each with its props, the page's props of the newest
 *    version or what an older version's downcast builds from them (PointDowncasts).
 * 2. The contributions in scope: each compiled fill of such a point, with the installation's
 *    overrides cms:build applied (priority, enabled, replacement winners), that the activation
 *    state of now (PanelActivation, cbox-cms.panel.disabled) leaves enabled, and whose Scope lets
 *    it apply to the page and to what the page is about (ViewSubject).
 * 3. `requires`: the viewer must hold the command or read the scope names, as the PermissionRule
 *    decides it from the viewer's grants (HeldPermissions), asked once for the page with every
 *    name. A fill the viewer may not see is never listed and never handed anything.
 * 4. Access: each fill is handed the point's props, and runs its data query, at the lower of the
 *    viewer's classification access and the addon's reads capability, so a member above what the
 *    addon may read is absent from what it gets, whatever the viewer may read; the core's own
 *    contributions, in the namespace cms, at the viewer's. The point's codec writes the props at
 *    that access (ContributionProps).
 * 5. The host's checks: the registration each addon's code must match (Registrations), with the
 *    commands its contributions may issue, and whether the viewer sees the detail of a failure.
 *
 * Nothing an addon does blanks the page: when the registry or the activation state cannot be
 * read the page gets no contribution, recorded in telemetry (Withheld). A page that renders no
 * point reads nothing. The fills keep the order the host renders them in, priority with the
 * lowest first, then the addon's namespace, then the contribution's id; the data queries run
 * apart, through RunContributionData.
 */
#[Experimental]
final readonly class ResolveContributions
{
    public function __construct(
        private RegistryCache $registry,
        private PanelActivation $activation,
        private HeldPermissions $permissions,
        private ContributionTelemetry $telemetry,
    ) {}

    /**
     * @throws PointDowncastRefused when the page hands a point props that are not the newest version's
     */
    public function resolve(PanelView $view): ActiveContributions
    {
        if ($view->points === []) {
            return new ActiveContributions($view->page);
        }

        try {
            $registry = $this->registry->read();
            $disabled = $this->activation->disabled();
        } catch (RegistryCacheMissing) {
            return $this->none($view, Withheld::RegistryMissing);
        } catch (MalformedRegistryCache) {
            return $this->none($view, Withheld::RegistryMalformed);
        } catch (InvalidPanelActivation) {
            return $this->none($view, Withheld::ActivationInvalid);
        }

        $candidates = $this->candidates($view, $registry, $disabled);

        if ($candidates === []) {
            return new ActiveContributions($view->page);
        }

        $required = [];

        foreach ($candidates as $candidate) {
            foreach ($candidate->fills as $fill) {
                if ($fill->scope->requires instanceof CommandName) {
                    $required[$fill->scope->requires->value] = $fill->scope->requires;
                }
            }
        }

        $held = $this->permissions->of($view->viewer, array_values($required));
        $points = [];

        foreach ($candidates as $candidate) {
            $active = [];

            foreach ($candidate->fills as $fill) {
                if ($fill->scope->requires instanceof CommandName && ! $held->holds($fill->scope->requires)) {
                    continue;
                }

                $viewer = $held->access->classificationAccess;
                $access = $fill->addon()->value === PanelCompiler::CORE_NAMESPACE ? $viewer : $viewer->atMost($registry->addon($fill->addon())->reads ?? ClassificationAccess::Public);
                $active[] = new ActiveFill($fill, $candidate->point->id(), $candidate->props, $access);
            }

            if ($active !== []) {
                $points[] = new ActivePoint($candidate->point->id(), $active, $candidate->point->declaration);
            }
        }

        return new ActiveContributions(
            $view->page,
            $points,
            Registrations::of($registry, $points),
            $held->access->classificationAccess->allows(ClassificationAccess::Internal),
        );
    }

    /**
     * Each point the page renders that has fills in scope, with its props and those fills.
     *
     * @return list<PointInScope>
     *
     * @throws PointDowncastRefused
     */
    private function candidates(PanelView $view, CompiledRegistry $registry, DisabledContributions $disabled): array
    {
        $downcasts = new PointDowncasts($registry);
        $declared = $registry->panelPointsOf($view->page);
        $candidates = [];

        foreach ($view->points as $rendered) {
            foreach ($declared as $point) {
                if (! $rendered->name->equals($point->id()->name)) {
                    continue;
                }

                $fills = array_values(array_filter(
                    $disabled->apply($point)->fills,
                    static fn (PanelFill $fill): bool => $fill->enabled
                        && ($fill->scope->pages === [] || array_any($fill->scope->pages, static fn (PageName $page): bool => $page->equals($view->page)))
                        && $view->subject->fits($fill->scope),
                ));

                if ($fills !== []) {
                    $candidates[] = new PointInScope($point, $downcasts->props($point->id(), $rendered->props), $fills);
                }
            }
        }

        return $candidates;
    }

    private function none(PanelView $view, Withheld $reason): ActiveContributions
    {
        $this->telemetry->withheld($view->page, $reason);

        return new ActiveContributions($view->page);
    }
}
