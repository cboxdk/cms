<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
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
use Cbox\Cms\Panel\Contributions\Domain\Catalogues;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActivePoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointInScope;
use Cbox\Cms\Panel\Contributions\Domain\Registrations;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\Shell;

/**
 * Works out, per request and page, the contributions a viewer gets (PRD 13.4):
 *
 * 1. The points the page renders: every version of each point the page lists, as the compiled
 *    registry declares them on the page or on the shell (Shell::PAGE), which every page behind
 *    the login renders around its own content, each with its props, the page's props of the newest
 *    version or what an older version's downcast builds from them (PointDowncasts).
 * 2. The contributions in scope: each compiled fill of such a point, with the installation's
 *    overrides cms:build applied (priority, enabled, replacement winners), that the activation
 *    state of now (PanelActivation, cbox-cms.panel.disabled) leaves enabled, and whose Scope lets
 *    it apply to the page and to what the page is about (ViewSubject).
 * 3. `requires`: the viewer must hold the command or read the scope names, as the PermissionRule
 *    decides it from the viewer's grants (HeldPermissions), asked once for the page with every
 *    name; an action must also be of a command the viewer may run, so no action is shown that the
 *    server would refuse (section 3.3 of the panel extension architecture); and a nav entry must
 *    link to a page the viewer gets, an addon's page among the active ones or one of the panel's
 *    own pages (OwnPage), whose own permission the entry's scope requires, so no entry leads to a
 *    page the viewer may not open. A fill the viewer may not see is never listed and never handed
 *    anything.
 * 4. Access: each fill is handed the point's props, and runs its data query, at the lower of the
 *    viewer's classification access and the addon's reads capability, so a member above what the
 *    addon may read is absent from what it gets, whatever the viewer may read; the core's own
 *    contributions, in the namespace cms, at the viewer's. The point's codec writes the props at
 *    that access (ContributionProps).
 * 5. Data: a slot fill's query runs wherever the fill is active; a page's query runs only on the
 *    page itself, the view whose page is the page's id, so the shell lists an addon's pages on
 *    every page without running their queries (ActiveFill::data()).
 * 6. The host's checks: the registration each addon's code must match (Registrations), with the
 *    commands its contributions may issue, and whether the viewer sees the detail of a failure.
 * 7. The texts: for each addon with an active contribution, its catalogue in the view's locale
 *    alone (Catalogues), which the host serves its contributions' t() from.
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
                foreach ($this->permissionsOf($fill) as $permission) {
                    $required[$permission->value] = $permission;
                }
            }
        }

        $held = $this->permissions->of($view->viewer, array_values($required));
        $viewer = $held->access->classificationAccess;
        $pages = [];
        $resolved = [];

        foreach ($candidates as $candidate) {
            $active = [];

            foreach ($candidate->fills as $fill) {
                if (array_any($this->permissionsOf($fill), static fn (CommandName $permission): bool => ! $held->holds($permission))) {
                    continue;
                }

                if ($fill->declaration instanceof PageContribution) {
                    $pages[$fill->contribution->value] = true;
                }

                $access = $fill->addon()->value === PanelCompiler::CORE_NAMESPACE ? $viewer : $viewer->atMost($registry->addon($fill->addon())->reads ?? ClassificationAccess::Public);
                $active[] = new ActiveFill($fill, $candidate->point->id(), $candidate->props, $access, $this->dataOf($fill, $view));
            }

            $resolved[] = [$candidate, $active];
        }

        $points = [];

        foreach ($resolved as [$candidate, $active]) {
            $active = array_values(array_filter(
                $active,
                static fn (ActiveFill $fill): bool => ! $fill->fill->declaration instanceof NavContribution || isset($pages[$fill->fill->declaration->page]) || OwnPage::named($fill->fill->declaration->page) instanceof OwnPage,
            ));

            if ($active !== []) {
                $points[] = new ActivePoint($candidate->point->id(), $active, $candidate->point->declaration);
            }
        }

        return new ActiveContributions(
            $view->page,
            $points,
            Registrations::of($registry, $points),
            $held->access->classificationAccess->allows(ClassificationAccess::Internal),
            Catalogues::of($registry, $points, $view->locale),
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

        if (! $view->page->equals(Shell::page())) {
            $declared = [...$declared, ...$registry->panelPointsOf(Shell::page())];
        }

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

    /**
     * The permissions the viewer must hold to get the fill: the one its scope requires, and for an
     * action the command it runs.
     *
     * @return list<CommandName>
     */
    private function permissionsOf(PanelFill $fill): array
    {
        $permissions = [];

        if ($fill->scope->requires instanceof CommandName) {
            $permissions[] = $fill->scope->requires;
        }

        if ($fill->declaration instanceof ActionContribution && $fill->command instanceof CommandRef) {
            $permissions[] = $fill->command->name;
        }

        return $permissions;
    }

    /**
     * The query whose result the fill gets as data on the page: a slot fill's wherever it is
     * active, a page's only on the page itself.
     */
    private function dataOf(PanelFill $fill, PanelView $view): ?CommandRef
    {
        if ($fill->declaration instanceof PageContribution && $fill->contribution->value !== $view->page->value) {
            return null;
        }

        return $fill->query;
    }

    private function none(PanelView $view, Withheld $reason): ActiveContributions
    {
        $this->telemetry->withheld($view->page, $reason);

        return new ActiveContributions($view->page);
    }
}
